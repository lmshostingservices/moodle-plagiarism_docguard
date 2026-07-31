<?php

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback'  => 'plagiarism_docguard\observer::on_assessable_submitted',
        'priority'  => 0,
        'internal'  => false,
    ],
];
