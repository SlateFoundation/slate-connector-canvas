<?php

Git::$repositories['slate-connector-canvas'] = [
    'remote' => 'git@github.com:SlateFoundation/slate-connector-canvas.git',
    'originBranch' => 'master',
    'workingBranch' => 'master',
    'trees' => [
        'html-templates/connectors/canvas',
        'php-classes/RemoteSystems/Canvas.php',
        'php-classes/Slate/Connectors/Canvas',
        'php-classes/Slate/UI/Adapters/Canvas.php',
        'php-config/Git.config.d/slate-connector-canvas.php',
        'php-config/Slate/Courses/Section.config.d/canvas-launcher.php',
        'php-config/Slate/UI/Omnibar.config.d/canvas.php',
        'php-config/Slate/DashboardRequestHandler.config.d/canvas.php',
        'php-migrations/Slate/Connectors/20160901_canvas-keys.php',
        'site-root/connectors/canvas.php'
    ]
];