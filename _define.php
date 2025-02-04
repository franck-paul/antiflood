<?php

/**
 * @brief Antiflood, an antispam filter plugin for Dotclear 2
 *
 * @package Dotclear
 * @subpackage Plugins
 *
 * @author Olivier Meunier and contributors
 *
 * @copyright Olivier Meunier and contributors
 * @copyright GPL-2.0 https://www.gnu.org/licenses/gpl-2.0.html
 */
$this->registerModule(
    'Anti flood',
    'Anti flood spam filter',
    'dcTeam',
    '4.4',
    [
        'date'        => '2003-08-13T13:42:00+0100',
        'requires'    => [['core', '2.28']],
        'permissions' => 'My',
        'priority'    => 200,
        'type'        => 'plugin',

        'details'    => 'https://open-time.net/?q=antiflood',
        'support'    => 'https://github.com/franck-paul/antiflood',
        'repository' => 'https://raw.githubusercontent.com/franck-paul/antiflood/main/dcstore.xml',
    ]
);
