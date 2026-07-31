<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'plagiarism/docguard:viewreport' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype'     => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'  => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
