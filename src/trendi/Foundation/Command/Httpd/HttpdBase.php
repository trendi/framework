<?php
/**
 * User: Peter Wang
 * Date: 16/9/15
 * Time: 下午10:19
 */

namespace Trendi\Foundation\Command\Httpd;

use Trendi\Config\Config;
use Trendi\Foundation\Application;
use Trendi\Server\HttpServer;
use Trendi\Support\Arr;
use Trendi\Support\Dir;
use Trendi\Support\ElapsedTime;
use Trendi\Support\Log;
use Trendi\Support\RunMode;

class HttpdBase
{
    public static function operate($cmd, $output, $input)
    {
        ElapsedTime::setStartTime(ElapsedTime::SYS_START);

        $root = Dir::formatPath(ROOT_PATH);

        self::setRelease();

        $config = Config::get("server.httpd");
        $appName = Config::get("server.name");

        if (!$appName) {
            Log::sysinfo("server.name not config");
            exit(0);
        }

        if (!$config) {
            Log::sysinfo("httpd config not config");
            exit(0);
        }

        if (!isset($config['server'])) {
            Log::sysinfo("httpd.server config not config");
            exit(0);
        }

        if ($input->hasOption("daemonize")) {
            $daemonize = $input->getOption('daemonize');
            $config['server']['daemonize'] = $daemonize == 0 ? 0 : 1;
        }

        if (!isset($config['server']['host'])) {
            Log::sysinfo("httpd.server.host config not config");
            exit(0);
        }

        if (!isset($config['server']['port'])) {
            Log::sysinfo("httpd.server.port config not config");
            exit(0);
        }

        $adapter = new Application($root);
        self::doOperate($cmd, $config, $adapter, $root, $appName);
    }

    protected static function setRelease()
    {
        $release = ROOT_PATH . "/storage/release";
        if (is_file($release)) {
            $releaseContent = file_get_contents($release);
            Config::set("_release.path", $releaseContent);
        }
    }


    public static function doOperate($command, array $config, $adapter, $root, $appName)
    {
        $defaultConfig = [
            'daemonize' => 0,
            //worker数量，推荐设置和cpu核数相等
            'worker_num' => 2,
            "dispatch_mode" => 2,
            //reactor数量，推荐2
            'reactor_num' => 2,
            'static_path' => $root . '/public',
            "gzip" => 4,
            "static_expire_time" => 86400,
            "task_worker_num" => 5,
            "task_fail_log" => "/tmp/task_fail_log",
            "task_retry_count" => 2,
            "serialization" => 1,
            "mem_reboot_rate" => 0,
            //以下配置直接复制，无需改动
            'open_length_check' => 1,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => 2000000,
            "pid_file" => "/tmp/pid",
            'open_tcp_nodelay' => 1,
        ];

        $config['server'] = Arr::merge($defaultConfig, $config['server']);

        $fisPath = Config::get("_release.path");
        if ($fisPath) {
            $config['server']['_release.path'] = $fisPath;
        }

        $serverName = $appName . "-httpd-master";
        exec("ps axu|grep " . $serverName . "$|awk '{print $2}'", $masterPidArr);
        $masterPid = $masterPidArr ? current($masterPidArr) : null;

        if ($command === 'start' && $masterPid) {
            Log::sysinfo("httpd server already running");
            return;
        }

        if($command == "start"||$command=='restart'){
            self::addRelease($config['server']['daemonize']);
        }

        if ($command !== 'start' && $command !== 'restart' && !$masterPid) {
            Log::sysinfo("[$serverName] not run");
            return;
        }
        switch ($command) {
            case 'status':
                if ($masterPid) {
                    Log::sysinfo("[$serverName]  already running");
                } else {
                    Log::sysinfo("[$serverName]  not run");
                }
                break;
            case 'start':
                self::start($config, $adapter, $appName);
                break;
            case 'stop':
                self::stop($appName);
                Log::sysinfo("[$serverName] stop success ");
                break;
            case 'restart':
                self::stop($appName);
                self::start($config, $adapter, $appName);
                break;
            default :
                return "";
        }
    }

    protected static function stop($appName)
    {
        $killStr = $appName . "-httpd";
        exec("ps axu|grep " . $killStr . "|awk '{print $2}'|xargs kill -9", $masterPidArr);
    }

    protected static function start($config, $adapter, $appName)
    {
        $swooleServer = new \swoole_http_server($config['server']['host'], $config['server']['port']);
        $obj = new HttpServer($swooleServer, $config['server'], $adapter, $appName);
        $obj->start();
    }

    protected static function addRelease($daemonize=0)
    {
        $file = [
            "fis-conf.js", "package.json"
        ];
        
        foreach ($file as $f) {
            $path = ROOT_PATH . "/" . $f;
            if (!is_file($path)) {
                Log::sysinfo($path . " not found   ---->----->");
                self::removeRelease();
                return;
            }
        }

        $nodeModulesPath = ROOT_PATH . "/node_modules";
        if (!is_dir($nodeModulesPath)) {
            if(!self::checkCmd("npm")) return ;
            exec("npm install");
        }

        if(!self::checkCmd("fis3")) return ;

        $log = ROOT_PATH."/storage/release";
        if(!is_writable($log)){
            Log::error($log." can not writable");
            return ;
        }

        $fisPath = ROOT_PATH."/public/release/".date('YmdHis');

        if(RunMode::getRunMode() == RunMode::RUN_MODE_TEST){
            $fisPath = ROOT_PATH."/public/release/_source";
        }
        if($daemonize){
            $cmdStr = "fis3 release prod -d ".$fisPath;
        }else{
            $cmdStr = "fis3 release -d ".$fisPath;
        }
        exec($cmdStr);
        file_put_contents($log, $fisPath);
    }

    protected static function removeRelease()
    {
        $log = ROOT_PATH."/storage/release";
        if(is_file($log)){
            unlink($log);
            return ;
        }
    }

    protected static function checkCmd($cmd)
    {
        $cmdStr = "command -v ".$cmd;
        exec($cmdStr, $check);
        if(!$check){
            Log::error("command {$cmd} Not Found");
            return "";
        }else{
            return current($check);
        }
    }

}