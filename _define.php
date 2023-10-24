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
    'antiflood',
    'Anti flood spam filter',
    'dcTeam',
    '4.0.2',
    [
        'requires'    => [['core', '2.28']],
        'permissions' => 'My',
        'priority'    => 200,
        'type'        => 'plugin',
    ]
);
