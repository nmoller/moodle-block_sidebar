<?php
/**
 * Created by PhpStorm.
 * User: nmoller
 * Date: 16-04-05
 * Time: 09:50
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

require_once($CFG->dirroot.'/blocks/side_bar/locallib.php');
require_once($CFG->dirroot.'/blocks/side_bar/block_side_bar.php');

class side_bar_after_restore_testcase extends advanced_testcase {

    /**
     *
     *
     * @throws coding_exception
     */
    public function test_after_restore() {
        global $DB;

        $this->resetAfterTest(true);
        // To be able to have global $USER
        $this->setAdminUser();
        $dg = $this->getDataGenerator();
        // Create test course data
        $course = $dg->create_course(array('format' => 'topics', 'numsections' => 1));
        $section = $dg->create_course_section(array('course' => $course->id, 'section' => 1));
        // Setup the course section for the Side Bar block-managed activities
        $sectioninfo = block_side_bar_create_section($course);

        $page = $dg->create_module('page', array('course' => $course->id), array('section' => $sectioninfo->section));

        // Setup the course section for the Side Bar block-managed activities
        $block = new stdClass();
        $ctx = context_course::instance($course->id);
        $block->blockname = 'side_bar';
        $block->parentcontextid = $ctx->id;
        $block->patypepattern = 'course-view-*';
        $block->defaultregion = 'side-post';
        $block->defaultweight = 2;
        $block->showinsubcontexts = 0;
        $cfg = new stdClass();
        $cfg->title = "Test";
        $cfg->section_id = $sectioninfo->id;

        $block->configdata = base64_encode(serialize($cfg));

        $block_ins = $DB->insert_record('block_instances', $block);

        $new_course_id = $this->backup_and_restore($course);

        $reseturl = new moodle_url('/blocks/side_bar/reset.php?cid='.$new_course_id);

        $newsection = new stdClass();
        $newsection->name          = get_string('sidebar', 'block_side_bar');
        $newsection->summary       = get_string('sectionsummary', 'block_side_bar', (string)html_writer::link($reseturl, $reseturl));
        $newsection->summaryformat = FORMAT_HTML;
        $newsection->visible       = true;

        $section = $DB->get_records('course_sections', array('name' => $newsection->name));
        $section = array_pop($section);
        $this->assertEquals($newsection->summary, $section->summary);
        $block_instance = $DB->get_records('block_instances', array('blockname'=>'side_bar'));
        $block_instance =array_pop($block_instance);
        $new_config = $block_instance->configdata;
        $new_config = unserialize(base64_decode($new_config));
        $this->assertEquals($section->id, $new_config->section_id);

    }

    /**
     * Backs a course up and restores it.
     *
     * @param stdClass $course Course object to backup
     * @param int $newdate If non-zero, specifies custom date for new course
     * @return int ID of newly restored course
     */
    protected function backup_and_restore($course, $newdate = 0) {
        global $USER, $CFG;

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = backup::LOG_NONE;

        // Do backup with default settings. MODE_IMPORT means it will just
        // create the directory and not zip it.
        $bc = new backup_controller(backup::TYPE_1COURSE, $course->id,
          backup::FORMAT_MOODLE, backup::INTERACTIVE_NO, backup::MODE_IMPORT,
          $USER->id);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Do restore to new course with default settings.
        $newcourseid = restore_dbops::create_new_course(
          $course->fullname, $course->shortname . '_2', $course->category);
        $rc = new restore_controller($backupid, $newcourseid,
          backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id,
          backup::TARGET_NEW_COURSE);
        if ($newdate) {
            $rc->get_plan()->get_setting('course_startdate')->set_value($newdate);
        }
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }
}