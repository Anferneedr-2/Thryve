<?php

namespace Tests\Feature;

use App\Ai\FileReader;
use App\Models\Attachment;
use App\Models\Role;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;
use ZipArchive;

class AttachmentTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake('private');
        config(['services.hermes.url' => 'http://hermes.test/p/bot', 'services.hermes.key' => 'k-1234567890123456']);
    }

    private function me(): array
    {
        $user = $this->makeUser(Role::EMPLOYEE);
        [, $cookie] = $this->signIn($user);

        return [$user, $cookie];
    }

    private function upload(string $cookie, UploadedFile $f)
    {
        return $this->device($cookie)->post('/api/attachments', ['file' => $f], ['Accept' => 'application/json']);
    }

    private function zipFile(string $name, array $parts): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tt');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($parts as $n => $data) {
            $zip->addFromString($n, $data);
        }
        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function docx(string ...$paragraphs): UploadedFile
    {
        $body = implode('', array_map(fn ($p) => "<w:p><w:r><w:t>{$p}</w:t></w:r></w:p>", $paragraphs));

        return $this->zipFile('memo.docx', ['word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>']);
    }

    private function xlsx(): UploadedFile
    {
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';

        return $this->zipFile('budget.xlsx', [
            'xl/workbook.xml' => "<workbook {$ns}><sheets><sheet name=\"Q4\" sheetId=\"1\"/></sheets></workbook>",
            'xl/sharedStrings.xml' => "<sst {$ns}><si><t>Item</t></si><si><t>Rent</t></si></sst>",
            'xl/worksheets/sheet1.xml' => "<worksheet {$ns}><sheetData><row><c t=\"s\"><v>0</v></c><c><v>Amount</v></c></row><row><c t=\"s\"><v>1</v></c><c><v>1200</v></c></row></sheetData></worksheet>",
        ]);
    }

    private function text(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_supported_files_are_read_and_stored_privately_under_a_random_name(): void
    {
        [$user, $cookie] = $this->me();

        $r = $this->upload($cookie, $this->text('notes.txt', "Hello\nWorld"))->assertCreated();

        $r->assertJsonPath('data.name', 'notes.txt')->assertJsonPath('data.kind', 'txt');
        $a = Attachment::sole();
        $this->assertSame("Hello\nWorld", $a->extracted_text);
        $this->assertSame($user->id, $a->user_id);
        $this->assertStringNotContainsString('notes', $a->storage_key);
        Storage::disk('private')->assertExists($a->storage_key);
        $this->assertArrayNotHasKey('storage_key', $r->json('data'));
        $this->assertArrayNotHasKey('extracted_text', $r->json('data'));
    }

    public function test_word_and_excel_text_is_extracted(): void
    {
        [, $cookie] = $this->me();

        $this->upload($cookie, $this->docx('Quarterly plan', 'Ship the beta'))->assertCreated();
        $this->upload($cookie, $this->xlsx())->assertCreated();

        $texts = Attachment::orderBy('id')->pluck('extracted_text')->all();
        $this->assertSame("Quarterly plan\nShip the beta", $texts[0]);
        $this->assertStringContainsString('## Sheet: Q4', $texts[1]);
        $this->assertStringContainsString("Item\tAmount", $texts[1]);
        $this->assertStringContainsString("Rent\t1200", $texts[1]);
    }

    public function test_csv_with_a_byte_order_mark_and_legacy_encoding_is_readable(): void
    {
        [, $cookie] = $this->me();

        $this->upload($cookie, $this->text('a.csv', "\xEF\xBB\xBFname,city\nJos\xE9,Manila"))->assertCreated();

        $this->assertSame("name,city\nJosé,Manila", Attachment::sole()->extracted_text);
    }

    public function test_the_name_is_not_trusted_the_content_must_match(): void
    {
        [, $cookie] = $this->me();

        $this->upload($cookie, $this->text('fake.docx', 'just text, not a zip'))->assertStatus(422)->assertJsonPath('error.code', 'file_unreadable');
        $this->upload($cookie, $this->zipFile('fake.xlsx', ['hello.txt' => 'x']))->assertStatus(422)->assertJsonPath('error.code', 'file_unreadable');
        $this->upload($cookie, $this->text('binary.txt', "abc\0def"))->assertStatus(422)->assertJsonPath('error.code', 'file_unreadable');
        $this->upload($cookie, $this->text('empty.txt', '   '))->assertStatus(422);
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_unsupported_types_are_refused_with_a_clear_message(): void
    {
        [, $cookie] = $this->me();

        foreach (['report.pdf', 'photo.png', 'photo.jpg', 'run.exe', 'page.html', 'noext'] as $name) {
            $this->upload($cookie, $this->text($name, 'content'))->assertStatus(422)->assertJsonPath('error.code', 'file_type_not_allowed');
        }
    }

    public function test_xml_entities_inside_office_files_are_not_expanded(): void
    {
        [, $cookie] = $this->me();
        $evil = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/hostname">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&x;visible</w:t></w:r></w:p></w:body></w:document>';

        $r = $this->upload($cookie, $this->zipFile('x.docx', ['word/document.xml' => $evil]));

        $stored = Attachment::first()?->extracted_text ?? '';
        $this->assertStringNotContainsString(trim((string) @file_get_contents('/etc/hostname')) ?: 'no-such-host-file', $stored);
        $this->assertContains($r->status(), [201, 422]);
    }

    public function test_size_and_count_limits_follow_the_settings(): void
    {
        [, $cookie] = $this->me();
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        Settings::update(['file_max_mb' => 1, 'file_max_count' => 2], $admin);

        $this->upload($cookie, $this->text('big.txt', str_repeat('a', 1024 * 1024 + 10)))->assertStatus(422)->assertJsonPath('error.code', 'file_too_large');
        $this->upload($cookie, $this->text('1.txt', 'one'))->assertCreated();
        $this->upload($cookie, $this->text('2.txt', 'two'))->assertCreated();
        $this->upload($cookie, $this->text('3.txt', 'three'))->assertStatus(422)->assertJsonPath('error.code', 'too_many_files');
        $this->device($cookie)->getJson('/api/attachments/limits')->assertJsonPath('data.max_count', 2)->assertJsonPath('data.max_mb', 1);
    }

    public function test_long_files_are_shortened_and_flagged(): void
    {
        [, $cookie] = $this->me();

        $r = $this->upload($cookie, $this->text('long.txt', str_repeat('word ', FileReader::MAX_CHARS)))->assertCreated();

        $r->assertJsonPath('data.truncated', true);
        $this->assertSame(FileReader::MAX_CHARS, mb_strlen(Attachment::sole()->extracted_text));
    }

    public function test_a_file_reaches_hermes_as_text_with_the_message_and_stays_with_the_conversation(): void
    {
        Http::fake(['*' => Http::response(['model' => 'bot', 'choices' => [['message' => ['content' => 'Summary ready'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5]])]);
        [, $cookie] = $this->me();
        $id = $this->upload($cookie, $this->docx('Budget is 5000'))->json('data.id');

        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Summarize this', 'attachment_ids' => [$id]])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req['messages'][0]['content'], 'Summarize this')
            && str_contains($req['messages'][0]['content'], '[Attached file: memo.docx]') && str_contains($req['messages'][0]['content'], 'Budget is 5000'));
        $conv = $r->json('data.conversation.id');
        $this->device($cookie)->getJson("/api/conversations/{$conv}")->assertJsonPath('data.messages.0.attachments.0.name', 'memo.docx');
        $a = Attachment::find($id);
        $this->assertNotNull($a->message_id);
        $this->assertSame($conv, $a->conversation_id);
        // Sent files are kept: they can no longer be removed.
        $this->device($cookie)->deleteJson("/api/attachments/{$id}")->assertNotFound();
        Storage::disk('private')->assertExists($a->storage_key);
    }

    public function test_the_file_is_sent_again_on_follow_ups_and_after_an_edit(): void
    {
        Http::fake(['*' => Http::response(['model' => 'bot', 'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5]])]);
        [, $cookie] = $this->me();
        $id = $this->upload($cookie, $this->text('data.txt', 'secret-sauce-recipe'))->json('data.id');
        $conv = $this->device($cookie)->postJson('/api/chat', ['message' => 'read it', 'attachment_ids' => [$id]])->json('data.conversation.id');

        $this->device($cookie)->postJson('/api/chat', ['message' => 'and now?', 'conversation_id' => $conv])->assertOk();
        Http::assertSent(fn ($req) => count($req['messages']) === 3 && str_contains($req['messages'][0]['content'], 'secret-sauce-recipe'));

        $first = \App\Models\Message::where('conversation_id', $conv)->where('role', 'user')->orderBy('id')->value('id');
        $this->device($cookie)->patchJson("/api/conversations/{$conv}/messages/{$first}", ['message' => 'read it again'])->assertOk();
        Http::assertSent(fn ($req) => count($req['messages']) === 1 && str_contains($req['messages'][0]['content'], 'secret-sauce-recipe') && str_contains($req['messages'][0]['content'], 'read it again'));
    }

    public function test_other_peoples_files_cannot_be_attached_downloaded_or_removed(): void
    {
        Http::fake();
        [, $a] = $this->me();
        [, $b] = $this->me();
        $id = $this->upload($a, $this->text('mine.txt', 'private notes'))->json('data.id');

        $this->device($b)->postJson('/api/chat', ['message' => 'hi', 'attachment_ids' => [$id]])->assertStatus(422)->assertJsonPath('error.code', 'file_not_found');
        $this->device($b)->get("/api/attachments/{$id}/download", ['Accept' => 'application/json'])->assertNotFound();
        $this->device($b)->deleteJson("/api/attachments/{$id}")->assertNotFound();
        Http::assertNothingSent();
        $this->assertSame(0, \App\Models\Conversation::count(), 'a rejected send creates nothing');
    }

    public function test_download_returns_the_original_bytes_as_an_attachment(): void
    {
        [, $cookie] = $this->me();
        $id = $this->upload($cookie, $this->text('notes.txt', 'exact bytes'))->json('data.id');

        $r = $this->device($cookie)->get("/api/attachments/{$id}/download", ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('exact bytes', $r->streamedContent());
        $this->assertStringContainsString('attachment', $r->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    public function test_an_unsent_file_can_be_removed_and_its_bytes_are_deleted(): void
    {
        [, $cookie] = $this->me();
        $id = $this->upload($cookie, $this->text('x.txt', 'temp'))->json('data.id');
        $key = Attachment::find($id)->storage_key;

        $this->device($cookie)->deleteJson("/api/attachments/{$id}")->assertOk();

        $this->assertNull(Attachment::find($id));
        Storage::disk('private')->assertMissing($key);
    }

    public function test_sensitive_data_inside_a_file_triggers_the_same_warning(): void
    {
        Http::fake();
        [, $cookie] = $this->me();
        $id = $this->upload($cookie, $this->text('cards.txt', 'card 4111 1111 1111 1111'))->json('data.id');

        $this->device($cookie)->postJson('/api/chat', ['message' => 'check this file', 'attachment_ids' => [$id]])->assertStatus(409)->assertJsonPath('error.code', 'sensitive_warning');
        Http::assertNothingSent();
        $this->assertNull(Attachment::find($id)->message_id, 'a warned send leaves the file unsent');
    }

    public function test_too_many_files_in_one_message_is_refused(): void
    {
        Http::fake();
        [, $cookie] = $this->me();

        $this->device($cookie)->postJson('/api/chat', ['message' => 'x', 'attachment_ids' => range(1, 7)])->assertStatus(422)->assertJsonPath('error.code', 'too_many_files');
    }

    public function test_files_need_a_signed_in_person(): void
    {
        $this->device(null)->postJson('/api/attachments', [])->assertStatus(401);
        $this->device(null)->getJson('/api/attachments/1/download')->assertStatus(401);
    }
}
