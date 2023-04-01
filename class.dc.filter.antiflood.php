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

use Dotclear\Helper\Html\Html;
use Dotclear\Helper\Network\Http;
use Dotclear\Plugin\antispam\SpamFilter;

class dcFilterAntiFlood extends SpamFilter
{
    public $name    = 'Anti Flood';
    public $has_gui = true;
    public $delay;
    public $send_error;

    private $con;
    private string $table;

    public function __construct()
    {
        parent::__construct();

        $this->con   = dcCore::app()->con;
        $this->table = dcCore::app()->prefix . initAntispam::SPAMRULE_TABLE_NAME;

        $this->delay      = dcCore::app()->blog->settings->antiflood->flood_delay;
        $this->send_error = dcCore::app()->blog->settings->antiflood->send_error;

        if ($this->delay == null) {
            dcCore::app()->blog->settings->antiflood->put('flood_delay', 60, 'integer', 'Delay in seconds beetween two comments from the same IP');
            $this->delay = 60;
        }
        if ($this->send_error == null) {
            dcCore::app()->blog->settings->antiflood->put('send_error', false, 'boolean', 'Whether the filter should reply with a 503 error code');
            $this->send_error = false;
        }
    }

    protected function setInfo()
    {
        $this->description = __('Anti flood');
    }

    public function isSpam($type, $author, $email, $site, $ip, $content, $post_id, &$status)
    {
        if ($this->checkIp($ip)) {
            if ($this->send_error) {
                Http::head(503, 'Service Unavailable');
                echo '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">' .
                    '<HTML><HEAD>' .
                    '<TITLE>503 ' . __('Service Temporarily Unavailable') . '</TITLE>' .
                    '</HEAD><BODY>' .
                    '<H1>' . __('Service Temporarily Unavailable') . '</H1>' .
                    __('The server is temporarily unable to service your request due to maintenance downtime or capacity problems. Please try again later.') .
                    '</BODY></HTML>';
                exit;
            }

            return(true);
        }

        return(null);
    }

    public function getStatusMessage($status, $comment_id)
    {
        return sprintf(__('Filtered by %s.'), $this->guiLink());
    }

    private function checkIP($cip)
    {
        $strReq = 'SELECT DISTINCT(rule_content) ' .
        'FROM ' . $this->table . ' ' .
        "WHERE rule_type = 'flood' " .
        "AND (blog_id = '" . dcCore::app()->blog->id . "' OR blog_id IS NULL) " .
        'ORDER BY rule_content ASC ';

        $rs = new dcRecord($this->con->select($strReq));
        while ($rs->fetch()) {
            [$ip, $time] = explode(':', $rs->rule_content);
            if (($cip == $ip) && (time() - (int) $time <= $this->delay)) {
                return true;
            }
        }

        $this->cleanOldRecords();

        $cur = $this->con->openCursor($this->table);

        $id = (new dcRecord($this->con->select('SELECT MAX(rule_id) FROM ' . $this->table)))->f(0) + 1;

        $cur->rule_id      = $id;
        $cur->rule_type    = 'flood';
        $cur->rule_content = (string) implode(':', [$cip,time()]);
        $cur->blog_id      = dcCore::app()->blog->id;

        $cur->insert();

        return false;
    }

    private function cleanOldRecords()
    {
        $ids = [];

        $strReq = 'SELECT rule_id, rule_content ' .
        'FROM ' . $this->table . ' ' .
        "WHERE rule_type = 'flood' " .
        "AND (blog_id = '" . dcCore::app()->blog->id . "' OR blog_id IS NULL) " .
        'ORDER BY rule_content ASC ';

        $rs = new dcRecord($this->con->select($strReq));
        while ($rs->fetch()) {
            [$ip, $time] = explode(':', $rs->rule_content);
            if (time() - (int) $time > $this->delay) {
                array_push($ids, $rs->rule_id);
            }
        }
        if (count($ids) > 0) {
            $this->removeRule($ids);
        }
    }

    private function removeRule($ids)
    {
        $strReq = 'DELETE FROM ' . $this->table . ' ';

        if (is_array($ids)) {
            foreach ($ids as &$v) {
                $v = (int) $v;
            }
            $strReq .= 'WHERE rule_id IN (' . implode(',', $ids) . ') ';
        } else {
            $ids = (int) $ids;
            $strReq .= 'WHERE rule_id = ' . $ids . ' ';
        }

        if (!dcCore::app()->auth->isSuperAdmin()) {
            $strReq .= "AND blog_id = '" . $this->con->escape(dcCore::app()->blog->id) . "' ";
        }

        $this->con->execute($strReq);
    }

    public function gui(string $url): string
    {
        $flood_delay = dcCore::app()->blog->settings->antiflood->flood_delay;
        $send_error  = dcCore::app()->blog->settings->antiflood->send_error;

        if (isset($_POST['flood_delay'])) {
            try {
                $flood_delay = $_POST['flood_delay'];
                $send_error  = isset($_POST['send_error']);

                dcCore::app()->blog->settings->antiflood->put('flood_delay', $flood_delay, 'string');
                dcCore::app()->blog->settings->antiflood->put('send_error', $send_error, 'boolean');

                Http::redirect($url . '&up=1');
            } catch (Exception $e) {
                dcCore::app()->error->add($e->getMessage());
            }
        }

        return '<form action="' . Html::escapeURL($url) . '" method="post">' .

            '<p><label class="classic">' . __('Delay:') . ' ' .
            form::field('flood_delay', 12, 128, $flood_delay) . '</label></p>' .

            '<p>' . __('Sets the delay in seconds beetween two comments from the same IP') . '</p>' .
            '<p><label class="classic">' . __('Send error code:') . ' ' .
            form::checkbox('send_error', 1, $send_error) . '</label></p>' .

            '<p>' . __('Sets whether the filter should reply with a 503 error code.') . '</p>' .
            '<p><input type="submit" value="' . __('save') . '" />' .
            dcCore::app()->formNonce() . '</p>' .

            '</form>';
    }
}
