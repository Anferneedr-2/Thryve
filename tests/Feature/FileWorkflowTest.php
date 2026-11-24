<?php

namespace Tests\Feature;

use App\Ai\BoundedPdfParser;
use App\Ai\FileGenerationFailed;
use App\Ai\FileReader;
use App\Ai\GeneratedFiles;
use App\Models\Attachment;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class FileWorkflowTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        Storage::fake('private');
        config(['services.hermes.url' => 'http://hermes.test/p/bot', 'services.hermes.key' => 'fixture-key']);
    }

    private function cookie(): string
    {
        [, $cookie] = $this->signIn($this->makeUser(Role::EMPLOYEE));

        return $cookie;
    }

    private function response(string $content): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 40]])]);
    }

    private function payload(string $format): array
    {
        return ['version' => 1, 'message' => 'Prepared for review.', 'files' => [['format' => $format, 'name' => 'Quarterly report', 'title' => 'Quarterly report', 'paragraphs' => ['Revenue reached 1200.', 'For human review.'], 'rows' => [['Item', 'Amount'], ['Revenue', 1200]], 'slides' => [['title' => 'Summary', 'bullets' => ['Revenue reached 1200.']]]]]];
    }

    public function test_images_are_validated_and_forwarded_inline_without_exposing_paths(): void
    {
        $cookie = $this->cookie();
        $image = UploadedFile::fake()->image('chart.png', 60, 40);
        $id = $this->device($cookie)->post('/api/attachments', ['file' => $image], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->response('A sample chart.');
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Describe this chart', 'attachment_ids' => [$id]])->assertOk();
        Http::assertSent(fn ($r) => collect($r['messages'])->contains(fn ($m) => is_array($m['content']) && str_starts_with($m['content'][1]['image_url']['url'] ?? '', 'data:image/png;base64,')) && ! $r->hasHeader('X-Hermes-Session-Id'));
        $this->device($cookie)->post('/api/attachments', ['file' => UploadedFile::fake()->createWithContent('fake.png', 'not an image')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_pdf_text_is_extracted_and_image_only_pdf_is_not_silently_accepted(): void
    {
        $pdf = new Dompdf;
        $pdf->loadHtml('<p>Synthetic quarterly revenue 1200</p>');
        $pdf->render();
        $cookie = $this->cookie();
        $this->device($cookie)->post('/api/attachments', ['file' => UploadedFile::fake()->createWithContent('report.pdf', $pdf->output())], ['Accept' => 'application/json'])->assertCreated();
        $this->assertStringContainsString('quarterly revenue', Attachment::sole()->extracted_text);
        $pdf = new Dompdf;
        $pdf->loadHtml('<div></div>');
        $pdf->render();
        $this->device($cookie)->post('/api/attachments', ['file' => UploadedFile::fake()->createWithContent('scanned.pdf', $pdf->output())], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_all_six_generated_formats_have_real_readable_bytes(): void
    {
        $generator = new GeneratedFiles;
        foreach (['csv', 'md', 'docx', 'xlsx', 'pdf', 'pptx'] as $format) {
            $file = $generator->render($this->payload($format)['files'][0]);
            $this->assertNotEmpty($file['bytes']);
            $path = $this->tmpDir.'/output.'.$format;
            file_put_contents($path, $file['bytes']);
            if (in_array($format, ['docx', 'xlsx', 'pdf', 'csv', 'md'])) {
                $this->assertStringContainsString('1200', FileReader::read($path, $format)['text']);
            } else {
                $zip = new \ZipArchive;
                $this->assertTrue($zip->open($path) === true);
                $this->assertStringContainsString('1200', $zip->getFromName('ppt/slides/slide1.xml'));
                $zip->close();
            }
        }
    }

    public function test_generated_downloads_are_private_and_remain_after_archive(): void
    {
        $cookie = $this->cookie();
        $this->response(json_encode($this->payload('docx')));
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Create a Word report', 'output_format' => 'docx'])->assertOk();
        $r->assertJsonPath('data.reply.content', 'Prepared for review.')->assertJsonPath('data.reply.attachments.0.generated', true);
        $a = Attachment::sole();
        $this->assertTrue($a->generated);
        $this->device($cookie)->get('/api/attachments/'.$a->id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->device($this->cookie())->getJson('/api/attachments/'.$a->id.'/download')->assertNotFound();
        $this->device($cookie)->patchJson('/api/conversations/'.$a->conversation_id, ['archived' => true])->assertOk();
        $this->device($cookie)->get('/api/attachments/'.$a->id.'/download')->assertOk();
    }

    public function test_malformed_or_wrong_format_generation_never_creates_an_artifact(): void
    {
        $cookie = $this->cookie();
        foreach (['{"version":1', json_encode($this->payload('xlsx'))] as $content) {
            $this->response($content);
            $this->device($cookie)->postJson('/api/chat', ['message' => 'Create report', 'output_format' => 'pdf'])->assertStatus(502)->assertJsonPath('error.code', 'file_generation_failed');
            $this->assertSame(0, Attachment::count());
            $this->assertSame([], Storage::disk('private')->allFiles('generated'));
        }
    }

    public function test_csv_and_xlsx_keep_formula_like_strings_as_data(): void
    {
        $g = new GeneratedFiles;
        foreach (['csv', 'xlsx'] as $format) {
            $spec = $this->payload($format)['files'][0];
            $spec['rows'] = [['=HYPERLINK("https://example.test")', '+SUM(1,2)', '@SUM(1)', '-2+3']];
            $file = $g->render($spec);
            if ($format === 'csv') {
                $this->assertStringContainsString("'=HYPERLINK", $file['bytes']);
            } else {
                $path = $this->tmpDir.'/safe.xlsx';
                file_put_contents($path, $file['bytes']);
                $wb = IOFactory::load($path);
                $this->assertSame('s', $wb->getActiveSheet()->getCell('A1')->getDataType());
                $wb->disconnectWorksheets();
            }
        }
    }

    public function test_regeneration_preserves_output_choice_and_old_artifact(): void
    {
        $cookie = $this->cookie();
        $this->response(json_encode($this->payload('csv')));
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Prepare CSV', 'output_format' => 'csv'])->assertOk();
        $id = $r->json('data.conversation.id');
        $original = Attachment::sole();
        $this->device($cookie)->postJson('/api/conversations/'.$id.'/regenerate')->assertOk()->assertJsonPath('data.reply.attachments.0.kind', 'csv');
        Storage::disk('private')->assertExists($original->storage_key);
        $this->assertSame(2, Attachment::count());
    }

    private function block(mixed $spec, string $before = 'Here is your report.', string $after = ''): string
    {
        return $before."\n\n```thryve-file\n".json_encode($spec)."\n```".($after !== '' ? "\n\n".$after : '');
    }

    public function test_without_a_chosen_format_the_agent_is_told_how_to_make_files_and_a_plain_answer_stays_chat(): void
    {
        $cookie = $this->cookie();
        $this->response("Here is a summary:\n\n| Item | Amount |\n|---|---|\n| Revenue | 1200 |");
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Show me the numbers as a table'])->assertOk()
            ->assertJsonPath('data.reply.content', "Here is a summary:\n\n| Item | Amount |\n|---|---|\n| Revenue | 1200 |")->assertJsonCount(0, 'data.reply.attachments');
        Http::assertSent(fn ($r) => collect($r['messages'])->contains(fn ($m) => $m['role'] === 'system' && str_contains($m['content'], 'thryve-file')));
        $this->assertSame(0, Attachment::count());
    }

    public function test_the_agent_decides_to_make_a_file_and_the_person_gets_the_note_and_a_private_download(): void
    {
        $cookie = $this->cookie();
        $spec = $this->payload('xlsx')['files'][0];
        $this->response($this->block($spec, 'I made the spreadsheet for you.', 'Check the totals before sharing.'));
        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Put these in a spreadsheet'])->assertOk();
        $r->assertJsonPath('data.reply.content', "I made the spreadsheet for you.\n\nCheck the totals before sharing.")->assertJsonPath('data.reply.attachments.0.kind', 'xlsx')->assertJsonPath('data.reply.attachments.0.generated', true);
        $this->assertStringNotContainsString('thryve-file', (string) $r->json('data.reply.content'));
        $a = Attachment::sole();
        $this->device($cookie)->get('/api/attachments/'.$a->id.'/download')->assertOk();
        $this->device($this->cookie())->getJson('/api/attachments/'.$a->id.'/download')->assertNotFound();

        // Asking again for the same message makes a fresh file and keeps the old one.
        $this->device($cookie)->postJson('/api/conversations/'.$r->json('data.conversation.id').'/regenerate')->assertOk()->assertJsonPath('data.reply.attachments.0.kind', 'xlsx');
        $this->assertSame(2, Attachment::count());
    }

    public function test_a_marked_block_that_is_broken_repeated_cut_off_or_not_a_file_never_shows_json_or_creates_a_file(): void
    {
        $cookie = $this->cookie();
        $good = $this->payload('csv')['files'][0];
        $cases = [
            "Sure.\n\n```thryve-file\n{\"format\":\"csv\",\"name\":\"x\"\n```",                       // broken JSON
            "Sure.\n\n```thryve-file\n{\"format\":\"csv\",\"name\":\"x\",\"title\":\"T\"",             // never closed
            $this->block($good).$this->block($good),                                                  // two files
            $this->block(['format' => 'exe', 'name' => 'x', 'title' => 'T', 'paragraphs' => ['a']]),  // not an allowed format
            $this->block(['format' => 'csv', 'name' => 'x', 'title' => 'T']),                         // nothing to put in it
            $this->block('not an object'),                                                            // wrong shape
        ];
        foreach ($cases as $content) {
            $this->response($content);
            $this->device($cookie)->postJson('/api/chat', ['message' => 'Make me a file'])->assertStatus(502)->assertJsonPath('error.code', 'file_generation_failed');
        }
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('private')->allFiles('generated'));

        // A reply that was cut off by the length limit while a block was open is refused too.
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $this->block($good)], 'finish_reason' => 'length']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 40]])]);
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Make me a file'])->assertStatus(502)->assertJsonPath('error.code', 'file_generation_failed');
        $this->assertSame(0, Attachment::count());
    }

    public function test_talking_about_the_block_without_using_it_stays_a_normal_reply(): void
    {
        $cookie = $this->cookie();
        $this->response('You can ask me for a Word file or an Excel sheet any time.');
        $this->device($cookie)->postJson('/api/chat', ['message' => 'What can you make?'])->assertOk()->assertJsonCount(0, 'data.reply.attachments');
    }

    public function test_refusal_and_invalid_format_do_not_create_files(): void
    {
        $cookie = $this->cookie();
        $this->response(json_encode(['version' => 1, 'message' => 'Please supply source data first.', 'files' => []]));
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Prepare report', 'output_format' => 'pdf'])->assertOk()->assertJsonPath('data.reply.content', 'Please supply source data first.')->assertJsonCount(0, 'data.reply.attachments');
        $this->device($cookie)->postJson('/api/chat', ['message' => 'Prepare report', 'output_format' => 'exe'])->assertStatus(422);
        $this->assertSame(0, Attachment::count());
    }

    public function test_multi_slide_powerpoint_is_a_valid_package_with_escaped_text(): void
    {
        $spec = $this->payload('pptx')['files'][0];
        $spec['slides'][] = ['title' => 'Risks & next steps', 'bullets' => ['Review <sample> data', 'Second point']];
        $file = (new GeneratedFiles)->render($spec);
        $path = $this->tmpDir.'/slides.pptx';
        file_put_contents($path, $file['bytes']);
        $zip = new \ZipArchive;
        $zip->open($path);
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($zip->getFromName('ppt/slides/slide2.xml')));
        $this->assertStringContainsString('Risks & next steps', $doc->textContent);
        $this->assertStringContainsString('Review <sample> data', $doc->textContent);
        $this->assertTrue($doc->loadXML($zip->getFromName('ppt/presentation.xml')));
        $this->assertSame(2, $doc->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldId')->length);
        $zip->close();
    }

    private function deck(array $slides): array
    {
        return ['format' => 'pptx', 'name' => 'Deck', 'title' => 'Deck', 'slides' => $slides];
    }

    private function openDeck(string $bytes): \ZipArchive
    {
        $path = $this->tmpDir.'/deck-'.bin2hex(random_bytes(3)).'.pptx';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));

        return $zip;
    }

    public function test_a_deck_with_only_slide_titles_is_made_and_is_a_valid_package(): void
    {
        foreach ([[['title' => 'Goals', 'bullets' => []], ['title' => 'Progress', 'bullets' => []], ['title' => 'Next steps', 'bullets' => []]],
            [['title' => 'Goals'], ['title' => 'Progress'], ['title' => 'Next steps']]] as $slides) {
            $zip = $this->openDeck((new GeneratedFiles)->render($this->deck($slides))['bytes']);
            $doc = new \DOMDocument;
            $this->assertTrue($doc->loadXML($zip->getFromName('ppt/presentation.xml')));
            $this->assertSame(3, $doc->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldId')->length);
            foreach ([1 => 'Goals', 2 => 'Progress', 3 => 'Next steps'] as $n => $title) {
                $xml = $zip->getFromName("ppt/slides/slide{$n}.xml");
                $this->assertTrue($doc->loadXML($xml), "slide {$n} is well-formed XML");
                $this->assertStringContainsString($title, $doc->textContent);
                $this->assertStringNotContainsString('THRYVE_', $xml, 'no template placeholder is left behind');
                foreach ($doc->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'txBody') as $body) {
                    $this->assertGreaterThanOrEqual(1, $body->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'p')->length, 'a text body keeps at least one paragraph');
                }
            }
            $zip->close();
        }
    }

    public function test_a_slide_with_more_than_five_bullets_continues_on_a_following_slide(): void
    {
        $zip = $this->openDeck((new GeneratedFiles)->render($this->deck([['title' => 'Goals', 'bullets' => ['1', '2', '3', '4', '5', '6', '7']], ['title' => 'Risks', 'bullets' => ['one']]]))['bytes']);
        $doc = new \DOMDocument;
        $doc->loadXML($zip->getFromName('ppt/presentation.xml'));
        $this->assertSame(3, $doc->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldId')->length);
        $doc->loadXML($zip->getFromName('ppt/slides/slide2.xml'));
        $this->assertStringContainsString('Goals (cont.)', $doc->textContent);
        $this->assertStringContainsString('6', $doc->textContent);
        $this->assertStringContainsString('7', $doc->textContent);
        $doc->loadXML($zip->getFromName('ppt/slides/slide3.xml'));
        $this->assertStringContainsString('Risks', $doc->textContent);
        $zip->close();
    }

    public function test_long_slide_text_is_cut_with_an_ellipsis_instead_of_refusing_the_deck(): void
    {
        $zip = $this->openDeck((new GeneratedFiles)->render($this->deck([['title' => str_repeat('T', 100), 'bullets' => [str_repeat('b', 200), '   ', 'short']]]))['bytes']);
        $doc = new \DOMDocument;
        $doc->loadXML($zip->getFromName('ppt/slides/slide1.xml'));
        $this->assertStringContainsString(str_repeat('T', 79).'…', $doc->textContent);
        $this->assertStringNotContainsString(str_repeat('T', 81), $doc->textContent);
        $this->assertStringContainsString(str_repeat('b', 119).'…', $doc->textContent);
        $this->assertStringContainsString('short', $doc->textContent);
        $zip->close();
    }

    public function test_a_deck_never_has_more_than_twenty_slides(): void
    {
        $slides = array_map(fn ($i) => ['title' => "Slide {$i}", 'bullets' => ['x']], range(1, 25));
        $zip = $this->openDeck((new GeneratedFiles)->render($this->deck($slides))['bytes']);
        $doc = new \DOMDocument;
        $doc->loadXML($zip->getFromName('ppt/presentation.xml'));
        $this->assertSame(20, $doc->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldId')->length);
        $zip->close();
    }

    public function test_slide_content_that_is_not_plain_text_is_still_refused(): void
    {
        $generator = new GeneratedFiles;
        foreach ([
            [['title' => 'Goals', 'bullets' => [['nested' => 'array']]]],
            [['title' => 'Goals', 'bullets' => 'not a list']],
            [['bullets' => ['no title']]],
            [['title' => '   ', 'bullets' => ['blank title']]],
            ['not even a slide'],
        ] as $slides) {
            try {
                $generator->render($this->deck($slides));
                $this->fail('Expected this slide content to be refused: '.json_encode($slides));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(FileGenerationFailed::class);
        $generator->render($this->deck([]));
    }

    public function test_a_refused_file_logs_why_but_never_the_content(): void
    {
        Log::spy();
        $payload = ['version' => 1, 'message' => 'Here it is.', 'files' => [$this->deck([['bullets' => ['SECRET-CONTENT-XYZ']]])]];
        try {
            (new GeneratedFiles)->decode(json_encode($payload), 'pptx');
            $this->fail('Expected the file to be refused');
        } catch (FileGenerationFailed) {
        }
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'slides.0.title') && ! str_contains($message, 'SECRET-CONTENT-XYZ'));
    }

    public function test_asking_for_a_deck_with_only_titles_through_chat_gives_a_powerpoint_download(): void
    {
        $cookie = $this->cookie();
        $this->response($this->block($this->deck([['title' => 'Goals', 'bullets' => []], ['title' => 'Progress', 'bullets' => []], ['title' => 'Next steps', 'bullets' => []]]), 'Here is your deck.'));

        $r = $this->device($cookie)->postJson('/api/chat', ['message' => 'Create a PowerPoint with 3 slides titled Goals, Progress and Next steps.'])->assertOk();

        $r->assertJsonPath('data.reply.attachments.0.kind', 'pptx')->assertJsonPath('data.reply.content', 'Here is your deck.');
        $this->device($cookie)->get('/api/attachments/'.Attachment::sole()->id.'/download')->assertOk();
    }

    public function test_pdf_aggregate_decompression_is_bounded_before_materializing_all_objects(): void
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Count 0 /Kids [] >>'];
        $compressed = gzcompress(str_repeat('Synthetic ', 25000));
        for ($i = 0; $i < 40; $i++) {
            $objects[] = '<< /Length '.strlen($compressed).' /Filter /FlateDecode >>'."\nstream\n".$compressed."\nendstream";
        }
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".count($offsets)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }
        $pdf .= 'trailer << /Size '.count($offsets).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF";
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PDF resource limit exceeded.');
        (new BoundedPdfParser)->parseContent($pdf);
    }
}
