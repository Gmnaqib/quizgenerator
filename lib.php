<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/editlib.php');

function quizgenerator_get_question_category($courseid)
{
    global $DB;
    // Ambil context course (contextlevel 50 adalah untuk course)
    $context = context_course::instance($courseid);
    $category = $DB->get_record('question_categories', ['contextid' => $context->id], '*', IGNORE_MISSING);
    return $category ? $category->id : null;
}

function quizgenerator_create_question($categoryid, $questiondata)
{
    global $DB, $USER;

    if (!$categoryid) {
        throw new moodle_exception('Invalid question category ID');
    }

    // Tentukan tipe soal (multichoice atau essay)
    $qtype = $questiondata->answers == NULL ? 'essay' : 'multichoice';

    // Buat objek pertanyaan
    $question = new stdClass();
    $question->category               = $categoryid;
    $question->name                   = $questiondata->name;
    $question->questiontext           = $questiondata->text;
    $question->questiontextformat     = FORMAT_HTML;
    $question->generalfeedback        = '';
    $question->generalfeedbackformat  = FORMAT_HTML;
    $question->qtype                  = $qtype;
    $question->defaultmark            = 1;
    $question->penalty                = 0.3333333;
    $question->penaltyformat          = FORMAT_HTML;
    $question->createdby              = $USER->id;
    $question->modifiedby             = $USER->id;
    $question->stamp                  = make_unique_id_code();
    $question->version                = 1;

    // Insert soal ke tabel 'question'
    $questionid = $DB->insert_record('question', $question);
    if (!$questionid) {
        throw new moodle_exception('Failed to insert question');
    }

    if ($qtype === 'essay') {
        $essayOptions = new stdClass();
        $essayOptions->questionid = $questionid;
        $essayOptions->responseformat = 'editor';
        $essayOptions->responserequired = 1;
        $essayOptions->responsefieldlines = 15;
        $essayOptions->minwordlimit = NULL;
        $essayOptions->maxwordlimit = NULL;
        $essayOptions->attachments = 0;
        $essayOptions->attachmentsrequired = 0;
        $essayOptions->graderinfo = NULL;
        $essayOptions->graderinfoformat = 0;
        $essayOptions->responsetemplate = NULL;
        $essayOptions->responsetemplateformat = 0;
        $essayOptions->maxbytes = 0;
        $essayOptions->filetypeslist = NULL;

        $DB->insert_record('qtype_essay_options', $essayOptions);
    }

    // Insert ke 'question_bank_entries'
    $qbe = new stdClass();
    $qbe->questioncategoryid = $categoryid;
    $qbe->ownerid = $USER->id;
    $questionbankentryid = $DB->insert_record('question_bank_entries', $qbe);
    if (!$questionbankentryid) {
        throw new moodle_exception('Failed to insert question bank entry');
    }

    // Insert ke 'question_versions'
    $qv = new stdClass();
    $qv->questionbankentryid = $questionbankentryid;
    $qv->version = 1;
    $qv->questionid = $questionid;
    $qv->status = "ready";
    $questionversionid = $DB->insert_record('question_versions', $qv);
    if (!$questionversionid) {
        throw new moodle_exception('Failed to insert question version');
    }

    // Hanya tambahkan jawaban jika tipe soal adalah multiple choice
    if ($qtype === 'multichoice') {
        foreach ($questiondata->answers as $answer) {
            $answerobj = new stdClass();
            $answerobj->question = $questionid;
            $answerobj->answer = $answer['text'];
            $answerobj->fraction = $answer['fraction'];
            $answerobj->feedback = '';
            $answerobj->feedbackformat = FORMAT_HTML;
            $DB->insert_record('Youtubes', $answerobj);
        }
    }

    return $questionid;
}

/**
 * Mengambil dokumen dari semua modul (resource) di sebuah course.
 *
 * @param int $courseid
 * @return array Array berisi dokumen dengan key: 'coursemodule', 'filename', 'url'
 */
function quizgenerator_get_course_documents($courseid)
{
    global $DB;
    $fs = get_file_storage();
    $modinfo = get_fast_modinfo($courseid);
    $cms = $modinfo->get_cms();
    $allfiles = [];
    foreach ($cms as $cm) {
        if (!$cm->uservisible) {
            continue;
        }
        $context = context_module::instance($cm->id);
        // Kita ambil dokumen dari modul resource (mod_resource)
        $component = 'mod_resource';
        $fileareas = ['content'];
        foreach ($fileareas as $filearea) {
            $files = $fs->get_area_files($context->id, $component, $filearea, false, 'timemodified DESC');
            foreach ($files as $file) {
                if (!$file->is_directory()) {
                    $allfiles[] = [
                        'coursemodule' => $cm->name,
                        'filename'     => $file->get_filename(),
                        'url'          => moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            $file->get_itemid(),
                            $file->get_filepath(),
                            $file->get_filename()
                        )
                    ];
                }
            }
        }
    }
    return $allfiles;
}

/**
 * Adds a new instance of the quizgenerator activity.
 *
 * @param object $quizgenerator Object containing the new instance data.
 * @return int The ID of the new record.
 */
function quizgenerator_add_instance($quizgenerator)
{
    global $DB;
    $quizgenerator->timecreated = time();
    $quizgenerator->timemodified = time();
    $id = $DB->insert_record('quizgenerator', $quizgenerator);
    return $id;
}

/**
 * Deletes an instance of the quizgenerator activity.
 *
 * @param int $id The ID of the instance to delete.
 * @return bool True if successful, false otherwise.
 */
function quizgenerator_delete_instance($id)
{
    global $DB;
    $DB->delete_records('quizgenerator', ['id' => $id]);
    return true;
}
