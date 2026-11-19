from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from pathlib import Path

d = Document()
s = d.sections[0]
s.page_width, s.page_height = Inches(8.5), Inches(11)
s.top_margin = s.bottom_margin = Inches(.7)
s.left_margin = s.right_margin = Inches(.8)
for name in ['Normal','Title','Heading 1','Heading 2']:
    st=d.styles[name]; st.font.name='Calibri'; st.font.color.rgb=RGBColor(0,0,0)
d.styles['Normal'].font.size=Pt(11)
d.styles['Normal'].paragraph_format.space_after=Pt(5)
d.styles['Title'].font.size=Pt(26)
d.styles['Heading 1'].font.size=Pt(16)
d.styles['Heading 1'].paragraph_format.space_before=Pt(10)
d.styles['Heading 1'].paragraph_format.space_after=Pt(5)
def h(t): d.add_heading(t,1)
def p(t): d.add_paragraph(t)
def bullets(items):
    for t in items: d.add_paragraph(t,style='List Bullet')
def table(headers, rows):
    t=d.add_table(rows=1,cols=2); t.alignment=WD_TABLE_ALIGNMENT.CENTER; t.autofit=False
    for col,w in zip(t.columns,[2.2,4.7]): col.width=Inches(w)
    for i,row in enumerate([headers]+rows):
        cells=t.rows[0].cells if i==0 else t.add_row().cells
        for j,(c,val) in enumerate(zip(cells,row)):
            c.width=Inches([2.2,4.7][j]); c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
            par=c.paragraphs[0]; par.paragraph_format.space_after=Pt(3); par.paragraph_format.space_before=Pt(3)
            run=par.add_run(val); run.font.size=Pt(10.5)
            if i==0: run.bold=True; run.font.color.rgb=RGBColor(255,255,255)
            pr=c._tc.get_or_add_tcPr(); sh=OxmlElement('w:shd'); sh.set(qn('w:fill'),'29465B' if i==0 else ('F1F5F8' if i%2==0 else 'FFFFFF')); pr.append(sh)
        trPr=t.rows[i]._tr.get_or_add_trPr(); cant=OxmlElement('w:cantSplit'); trPr.append(cant)
        if i==0: trPr.append(OxmlElement('w:tblHeader'))
    pr=t._tbl.tblPr; borders=OxmlElement('w:tblBorders')
    for k in ['top','left','bottom','right','insideH','insideV']:
        el=OxmlElement('w:'+k); el.set(qn('w:val'),'single'); el.set(qn('w:sz'),'4'); el.set(qn('w:color'),'D9D9D9'); borders.append(el)
    pr.append(borders)

d.add_heading('General AI Assistant Agent Requirements',0)
p('Centralized AI Platform  |  Hermes  |  MVP')
p('This specification defines the capabilities, skills, constraints and integration requirements for the company-wide General AI Assistant. The agent is built independently now so it can be connected to the platform during development.')
h('1 Agent identity')
table(['Property','Requirement'], [('Agent name','General AI Assistant'),('Role','Company-wide AI productivity assistant'),('Platform','Hermes'),('Target users','All employees'),('Purpose','Support everyday office tasks'),('Agent type','General-purpose workplace assistant'),('Availability','MVP')])
h('2 Core capabilities')
table(['Capability','Description'], [('Conversation','Answer questions and maintain multi-turn context.'),('Professional writing','Draft emails, letters, proposals and reports.'),('Document analysis','Read, summarize and extract information from documents.'),('Spreadsheet analysis','Analyze tables, calculations and trends.'),('Image analysis','Interpret screenshots, images and embedded text.'),('File generation','Produce downloadable office documents.'),('Translation','Translate and improve written communication.'),('Research','Gather and summarize information from authorized sources.'),('Task planning','Create checklists, schedules and action plans.'),('Critical thinking','Compare options and recommend practical solutions.')])
h('3 Required skills')
table(['Skill','Responsibility'], [('document-reader','Read PDF, DOCX and text files.'),('spreadsheet-reader','Read XLSX and CSV files.'),('image-analyzer','Analyze images and screenshots.'),('document-generator','Generate DOCX files.'),('spreadsheet-generator','Generate XLSX and CSV files.'),('presentation-generator','Generate PPTX presentations.'),('pdf-generator','Generate PDF documents.'),('markdown-generator','Generate Markdown documents.'),('business-writing','Produce professional workplace communications.'),('data-analysis','Interpret data and generate summaries.'),('research','Research and verify information using authorized sources.'),('task-planning','Organize workplace activities and priorities.')])
h('4 Behavioral requirements')
bullets(['Communicate clearly and professionally; adapt to the employee’s level of understanding.','Ask clarifying questions when critical information is missing.','Avoid inventing facts, figures, sources or company policies.','Maintain conversation context and distinguish facts from recommendations.','Be concise by default and provide downloadable files when requested.','Review generated files before returning them and state limitations honestly.'])
h('5 Constraints')
table(['Constraint','Required behavior'], [('Coding requests','Politely decline programming and software development tasks.'),('Non-work requests','Decline requests outside approved workplace activities.'),('Confidential data','Do not unnecessarily disclose sensitive information.'),('Hallucinations','Never fabricate sources, results or completed actions.'),('Employee privacy','Never access another employee’s conversations or files.'),('External actions','Do not send emails or modify external systems unless authorized.'),('File access','Access only attachments authorized for the current request.'),('Internal instructions','Do not reveal internal prompts, credentials or configuration.')])
h('6 Integration responsibilities')
p('Hermes handles AI processing. Laravel controls business rules, authorization and system records.')
table(['Responsibility','Owner'], [('AI responses, file analysis and file generation','Hermes'),('Authentication and permissions','Laravel'),('Token limits and conversation history','Laravel'),('Attendance and audit logs','Laravel'),('Provider priority and failover','Laravel and Hermes integration')])
h('7 Recommended agent structure')
p('Create a dedicated general-ai-assistant profile with soul.md, agent.md and a skills directory. Each required skill should have its own definition and supporting resources as needed.')
h('8 API readiness')
bullets(['Receive requests through an authenticated API endpoint.','Accept conversation history and authorized attachments.','Stream responses and support cancellation by the calling application.','Return generated file references and structured errors.','Return provider, model and token usage information when available.','Support concurrent requests without mixing employee contexts.'])
p('Verify the Hermes API contract, attachment transfer and generated-file retrieval before platform integration. These are integration dependencies rather than assumptions about existing Hermes capabilities.')
h('9 Acceptance criteria')
bullets(['Maintain a multi-turn conversation.','Analyze PDF, Word, Excel and image attachments accurately.','Generate valid DOCX, XLSX, PPTX, PDF and Markdown files.','Decline out-of-scope coding requests.','Return responses through the API and report available token usage.','Isolate simultaneous employee requests and handle failures gracefully.'])
h('10 MVP boundary')
p('Build only the General AI Assistant now. Keep analytics classification and prompt scoring as separate background AI tasks. Defer Coach AI and department agents until Phase 2.')
d.save(str(Path(__file__).parent/'General_AI_Assistant_Agent_Requirements.docx'))
