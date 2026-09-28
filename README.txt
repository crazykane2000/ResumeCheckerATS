RESUME SCAN DEMO
================

Purpose
-------
This is a very small proof-of-concept to check whether resumes can be read/scanned and compared with a Job Description.

Supported uploads
-----------------
- PDF
- DOCX
- DOC

Recommended setup (Laragon / local PHP)
---------------------------------------
1. Put the folder inside your web root, e.g.
   C:\laragon\www\resume-scan-demo

2. Open Terminal/CMD in this folder.

3. Run:
   composer install

4. Make sure PHP extensions are enabled:
   - zip
   - mbstring

5. Open:
   http://localhost/resume-scan-demo/

PDF
---
PDF works through smalot/pdfparser after `composer install`.
If Composer is not used, the code can also fall back to `pdftotext` if Poppler is installed.

DOCX
----
DOCX is read directly using PHP ZipArchive.

DOC
---
Old .DOC files are binary. This demo tries these tools if available:
- antiword
- catdoc
- LibreOffice headless

On Windows, the easiest practical option is to have LibreOffice installed and available in PATH, or convert old DOC files to DOCX for the first test.

What this first demo DOES
-------------------------
- Reads resume text
- Detects email
- Detects Indian-style mobile number
- Compares JD words with resume text
- Shows a basic percentage
- Shows matched and missing JD keywords
- Displays extracted resume text

What this first demo DOES NOT do yet
------------------------------------
- AI parsing
- Accurate ATS scoring
- Skill normalization
- Experience-year calculation
- Qualification logic
- OCR for scanned/image-only PDF
- Database
- Bulk 3000 resume processing
- Automatic email

If the extracted text looks correct, the next version can add structured AI parsing, skills/experience extraction, real JD scoring, database, bulk upload and email workflow.
