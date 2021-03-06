<?php
/**
 * 服务器的脚本管理和启动检测
 * author:houpeng
 * time:2017-05-29
 */
namespace xiaochengfu\swoole;

class SwooleService{

    /**
     * 配置对象
     * @var array
     */
    private $settings = [];


    function __construct($settings){
        $this->settings = $settings;
        $this->check();
    }

    /**
     * Description:  check
     * Author: hp <xcf-hp@foxmail.com>
     */
    private function check(){
        /**
        * 检测 PDO_MYSQL
        */
        if (!extension_loaded('pdo_mysql')) {
            exit('error:请安装PDO_MYSQL扩展' . PHP_EOL);
        }
        /**
        * 检查exec 函数是否启用
        */
        if (!function_exists('exec')) {
            exit('error:exec函数不可用' . PHP_EOL);
        }
        /**
        * 检查命令 lsof 命令是否存在
        */
        exec("whereis lsof", $out);
        if (strpos($out[0], "lsof") === false ) {
            exit('error:找不到lsof命令' . PHP_EOL);
        }
		/**
        * 检查目录是否存在并赋予权限
        */
		if(!is_dir($this->settings['log_dir'])) {
            mkdir($this->settings['log_dir'], 0777, true);
		}
    }

    /**
     * Description:  获取指定端口的服务占用列表
     * Author: hp <xcf-hp@foxmail.com>
     * @param $port
     * @return array
     */
    private function bindPort($port) {
        $res = [];
        $cmd = "lsof -i :{$port}|awk '$1 != \"COMMAND\"  {print $1, $2, $9}'";
        //eg:  php 7935 localhost:9512
        exec($cmd, $out);
        if ($out) {
            foreach ($out as $v) {
                $a = explode(' ', $v);
                list($ip, $p) = explode(':', $a[2]);
                $res[$a[1]] = [
                    'cmd'  => $a[0],
                    'ip'   => $ip === 'localhost'?'127.0.0.1':$ip,
                    'port' => $p,
                ];
            }
        }
        return $res;
    }

    /**
     * Description:  启动服务
     * Author: hp <xcf-hp@foxmail.com>
     */
    public function serviceStart(){

        $pidfile = $this->settings['pidfile'];
        $host = $this->settings['host'];
        $port = $this->settings['port'];

        $this->msg("服务正在启动...");
        if (!is_writable(dirname($pidfile))) {
            $this->error("pid文件需要写入权限".dirname($pidfile));
        }
        if (file_exists($pidfile)) {
            $pid = explode("\n", file_get_contents($pidfile));
            $cmd = "ps ax | awk '{ print $1 }' | grep -e \"^{$pid[0]}$\"";
            exec($cmd, $out);
            if (!empty($out)) {
                $this->msg("[warning]:pid文件已存在,服务已经启动,进程id为:{$pid[0]}",true);
            } else {
                $this->msg("[warning]:pid文件已存在,可能是服务上次异常退出");
                unlink($pidfile);
            }
        }

        $bind = $this->bindPort($port);
        if ($bind) {
            foreach ($bind as $k => $v) {
                if ($v['ip'] == '*' || $v['ip'] == $host) {
                    $this->error("服务启动失败,{$host}:{$port}端口已经被进程ID:{$k}占用");
                }
            }
        }

        //启动
        $server = new SwooleSetWebSocket($this->settings);
        $server->run();
        
    }

    /**
     * Description:  查看服务状态
     * Author: hp <xcf-hp@foxmail.com>
     */
    public function serviceStats(){
        $client = new \swoole_http_client($this->settings['host'],$this->settings['port']);
        $client->on('message', function ($cli, $frame){
            var_dump($frame);
            $cli->close();
        });

        $client->upgrade('/', function ($client){
            $client->push('stats');
//            $cli->close();
        });

    }

    /**
     * Description:  查看进程列表
     * Author: hp <xcf-hp@foxmail.com>
     */
    public function serviceList(){

        $cmd = "ps aux|grep " . $this->settings['process_name'] . "|grep -v grep|awk '{print $1, $2, $6, $8, $9, $11}'";
        exec($cmd, $out);

        if (empty($out)) {
            $this->msg("没有发现正在运行服务",true);
        }

        $this->msg("本机运行的服务进程列表:");
        $this->msg("USER PID RSS(kb) STAT START COMMAND");

        foreach ($out as $v) {
            $this->msg($v);
        }

    }

    /**
     * Description:  停止服务
     * Author: hp <xcf-hp@foxmail.com>
     */
    public function serviceStop(){

        $pidfile = $this->settings['pidfile'];

        $this->msg("正在停止服务...");

        if (!file_exists($pidfile)) {
            $this->error("pid文件:". $pidfile ."不存在");
        }
        $pid = explode("\n", file_get_contents($pidfile));

        if ($pid[0]) {
            $cmd = "kill {$pid[0]}";
            exec($cmd);
            do {
                $out = [];
                $c = "ps ax | awk '{ print $1 }' | grep -e \"^{$pid[0]}$\"";
                exec($c, $out);
                if (empty($out)) {
                    break;
                }else{
                    exec("kill -9 {$pid[0]}");
                }
            } while (true);
        }

        //确保停止服务后swoole-task-pid文件被删除
        if (file_exists($pidfile)) {
            unlink($pidfile);
        }
        $this->msg("服务已停止");

    }

    /**
     * Description:  msg
     * Author: hp <xcf-hp@foxmail.com>
     * @param $msg
     * @param bool $exit
     */
    private function msg($msg,$exit=false){

        if($exit){
            exit($msg . PHP_EOL);
        }else{
            echo $msg . PHP_EOL;
        }
    }

    /**
     * Description:  error
     * Author: hp <xcf-hp@foxmail.com>
     * @param $msg
     */
    private function error($msg){
        exit("[error]:".$msg . PHP_EOL);
    }
    
}


