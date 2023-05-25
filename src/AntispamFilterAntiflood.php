<?php
/**
 * @brief antiflood, a plugin for Dotclear 2
 *
 * @package Dotclear
 * @subpackage Plugins
 *
 * @author Franck Paul and contributors
 *
 * @copyright Franck Paul carnet.franck.paul@gmail.com
 * @copyright GPL-2.0 https://www.gnu.org/licenses/gpl-2.0.html
 */
declare(strict_types=1);

namespace Dotclear\Plugin\antiflood;

use dcCore;
use dcNamespace;
use dcPage;
use Dotclear\Database\Statement\DeleteStatement;
use Dotclear\Database\Statement\SelectStatement;
use Dotclear\Helper\Html\Form\Checkbox;
use Dotclear\Helper\Html\Form\Form;
use Dotclear\Helper\Html\Form\Label;
use Dotclear\Helper\Html\Form\Number;
use Dotclear\Helper\Html\Form\Para;
use Dotclear\Helper\Html\Form\Submit;
use Dotclear\Helper\Html\Form\Text;
use Dotclear\Helper\Network\Http;
use Dotclear\Plugin\antispam\SpamFilter;
use Exception;
use initAntispam;

class AntispamFilterAntiflood extends SpamFilter
{
    /** @var string Filter name */
    public $name = 'Anti Flood';

    /** @var bool Filter has settings GUI? */
    public $has_gui = true;

    public $delay;
    public $send_error;

    private $con;
    private string $table;

    /**
     * Constructs a new instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->con   = dcCore::app()->con;
        $this->table = dcCore::app()->prefix . initAntispam::SPAMRULE_TABLE_NAME;

        $settings = dcCore::app()->blog->settings->get(My::id());

        $this->delay      = $settings->flood_delay;
        $this->send_error = $settings->send_error;

        if ($this->delay === null) {
            $settings->put('flood_delay', 60, dcNamespace::NS_INT, 'Delay in seconds beetween two comments from the same IP');
            $this->delay = 60;
        }
        if ($this->send_error === null) {
            $settings->put('send_error', false, dcNamespace::NS_BOOL, 'Whether the filter should reply with a 503 error code');
            $this->send_error = false;
        }
    }

    /**
     * Sets the filter description.
     */
    protected function setInfo()
    {
        $this->description = __('Anti flood');
    }

    /**
     * This method should return if a comment is a spam or not. If it returns true
     * or false, execution of next filters will be stoped. If should return nothing
     * to let next filters apply.
     *
     * Your filter should also fill $status variable with its own information if
     * comment is a spam.
     *
     * @param      string  $type     The comment type (comment / trackback)
     * @param      string  $author   The comment author
     * @param      string  $email    The comment author email
     * @param      string  $site     The comment author site
     * @param      string  $ip       The comment author IP
     * @param      string  $content  The comment content
     * @param      int     $post_id  The comment post_id
     * @param      string  $status   The comment status
     */
    public function isSpam(string $type, ?string $author, ?string $email, ?string $site, ?string $ip, ?string $content, ?int $post_id, string &$status)
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

    /**
     * This method returns filter status message. You can overload this method to
     * return a custom message. Message is shown in comment details and in
     * comments list.
     *
     * @param      string  $status      The status
     * @param      int     $comment_id  The comment identifier
     *
     * @return     string  The status message.
     */
    public function getStatusMessage(string $status, ?int $comment_id)
    {
        return sprintf(__('Filtered by %s.'), $this->guiLink());
    }

    private function checkIP($cip)
    {
        $sql = new SelectStatement();
        $sql
            ->field('rule_content')
            ->distinct(true)
            ->from($this->table)
            ->where('rule_type = ' . $sql->quote('flood'))
            ->and($sql->orGroup([
                'blog_id = ' . $sql->quote(dcCore::app()->blog->id),
                $sql->isNull('blog_id'),
            ]))
            ->order('rule_content ASC')
        ;

        $rs = $sql->select();
        while ($rs->fetch()) {
            [$ip, $time] = explode(':', $rs->rule_content);
            if (($cip == $ip) && (time() - (int) $time <= $this->delay)) {
                return true;
            }
        }
        unset($sql);

        $this->cleanOldRecords();

        $sql = new SelectStatement();
        $sql
            ->field($sql->max('rule_id'))
            ->from($this->table)
        ;
        $rs = $sql->select();
        $id = $rs ? $rs->f(0) + 1 : 1;

        $cur               = $this->con->openCursor($this->table);
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

        $sql = new SelectStatement();
        $sql
            ->fields(['rule_id', 'rule_content'])
            ->from($this->table)
            ->where('rule_type = ' . $sql->quote('flood'))
            ->and($sql->orGroup([
                'blog_id = ' . $sql->quote(dcCore::app()->blog->id),
                $sql->isNull('blog_id'),
            ]))
            ->order('rule_content ASC')
        ;

        $rs = $sql->select();
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
        $sql = new DeleteStatement();
        $sql
            ->from($this->table)
        ;

        if (is_array($ids)) {
            foreach ($ids as &$v) {
                $v = (int) $v;
            }
            $sql->where('rule_id' . $sql->in($ids, 'int'));
        } else {
            $ids = (int) $ids;
            $sql->where('rule_id = ' . $ids);
        }

        if (!dcCore::app()->auth->isSuperAdmin()) {
            $sql->and('blog_id = ' . $sql->quote(dcCore::app()->blog->id));
        }

        $sql->delete();
    }

    /**
     * This method is called when you enter filter configuration. Your class should
     * have $has_gui property set to "true" to enable GUI.
     *
     * @param      string  $url    The GUI url
     *
     * @return     string  The GUI HTML content
     */
    public function gui(string $url): string
    {
        $settings    = dcCore::app()->blog->settings->get(My::id());
        $flood_delay = $settings->flood_delay;
        $send_error  = $settings->send_error;

        if (isset($_POST['af_send'])) {
            try {
                $flood_delay = (int) $_POST['flood_delay'];
                $send_error  = isset($_POST['send_error']);

                $settings->put('flood_delay', $flood_delay, dcNamespace::NS_INT);
                $settings->put('send_error', $send_error, dcNamespace::NS_BOOL);

                dcPage::addSuccessNotice(__('Filter configuration have been successfully saved.'));
                Http::redirect($url);
            } catch (Exception $e) {
                dcCore::app()->error->add($e->getMessage());
            }
        }

        return
        (new Form('antiflood-form'))
        ->action($url)
        ->method('post')
        ->fields([
            (new Para())->items([
                (new Number('flood_delay', 0, 999, (int) $flood_delay))
                    ->label((new Label(__('Delay:'), Label::INSIDE_TEXT_BEFORE))),
            ]),
            (new Para())->class('form-note')->items([
                (new Text(null, __('Sets the delay in seconds beetween two comments from the same IP'))),
            ]),
            (new Para())->items([
                (new Checkbox('send_error', $send_error))
                    ->value(1)
                    ->label((new Label(__('Send error code:'), Label::INSIDE_TEXT_AFTER))),
            ]),
            (new Para())->class('form-note')->items([
                (new Text(null, __('Sets whether the filter should reply with a 503 error code.'))),
            ]),
            (new Para())->items([
                (new Submit(['af_send'], __('Save')))
                    ->accesskey('s'),
                dcCore::app()->formNonce(false),
            ]),
        ])
        ->render();
    }
}
