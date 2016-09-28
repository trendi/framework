<?php
/**
 *  发送邮件email
 *
 * User: Peter Wang
 * Date: 16/9/18
 * Time: 下午12:46
 */

namespace Trendi\Foundation\Bootstrap\Task;

use Trendi\Config\Config;
use Trendi\Foundation\Exception\ConfigNotFoundException;

class Email
{
    /**
     *  执行函数
     * 
     * @param $receiver
     * @param $sender
     * @param $title
     * @param $msg
     * @return int
     * @throws ConfigNotFoundException
     */
    public function perform($receiver, $sender, $title, $msg)
    {
        $sender = is_array($sender) ? $sender : [$sender => $sender];
        $receiver = is_array($receiver) ? $receiver : [$receiver];

        $message = \Swift_Message::newInstance()
            ->setSubject($title)
            ->setFrom($sender)
            ->setTo($receiver)
            ->setBody($msg, 'text/html', 'utf-8');

        $config = Config::get("email.server");
        dump($config);
        if (!$config) {
            throw new ConfigNotFoundException("email.server not config");
        }

        $transport = \Swift_SmtpTransport::newInstance($config['smtp'], $config['port'])
            ->setUsername($config['username'])
            ->setPassword($config['password'])
            ->setEncryption($config['encryption']);

        $mailer = \Swift_Mailer::newInstance($transport);

        $failures = [];
        $result = $mailer->send($message, $failures);
        if (!$result) {
            dump($result);
        }
        return $result;
    }
}