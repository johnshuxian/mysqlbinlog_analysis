<?php
header("Content-Type：text/html; charset=utf-8");

class mysql_analysis
{
    protected $file;//binlog 解析后的伪sql文件

    protected $mysqli;//MySQL连接句柄

    protected $table;//表结构保存

    protected $primaryKey;//表主键

    protected $database;//当前数据库

    protected $lines;//文件总行数

    protected $outFile;//SQL写入文件

    protected $key;//行数计数器，用于统计进度

    protected $searchTable = '';//指定表筛选

    protected $exceptTable = '';//排除指定表

    protected $type;//操作类型

    protected $startTime;//程序开始时间戳

    protected $sqlNums = 0;//已解析出的SQL数量

    protected $rollback = false;//回滚SQL

    protected $rk = false;//insert去除主键

    protected $mysqlbinlog;

    protected $startDatetTime;

    protected $stopDateTime;

    public function __construct()
    {
        set_time_limit(0);//不限制脚本执行时间

        $param_arr = getopt("m:h:u:d:p:",
            [
                "port:",
                "output::",
                "help::",
                "table::",
                "type::",
                "except::",
                "rollback::",
                "rk::",
                "start-datetime:",
                "stop-datetime:",
                "mysqlbinlog:"
            ]);

        $info = <<<EOF
        
        example1(本地解析示例): php mysql_analysis.php -h"10.0.108.58" -ubee -dbee -pzxzdapp666 -maa.txt --output=aa.sql --table=wallet/user_role/jobs --except=wallet/user_role --port=3306
        
        example2(远程解析示例):php mysql_analysis.php -h"10.0.108.58" -ubee -dbee-master -pzxzdapp666 --output=drop.sql  --start-datetime="2019-11-29 00:00:00" --stop-datetime="2019-11-29 17:00:00" --except=jobs --mysqlbinlog=/usr/local/mysql-5.7.25-macos10.14-x86_64/bin/mysqlbinlog
        
            注：此脚本不指定-m情况下，需要执行shell_exec函数，请注意放行
            -m 指定binlog解析出的分析文件(mysqlbinlog mysql-bin.* --base64-output=decode-rows -vv >> xxxx.txt 解析后的文件，目前支持row/statement模式，理论支持MIXED)，不指定-m则表示使用远程获取
            -h 指定数据库连接地址
            -u 指定用户名
            -p 指定数据库密码
            -d 指定数据库
            --port= 指定数据库端口 可选
            --mysqlbinlog 指定系统mysqlbinlog绝对地址（远程获取binlog时必选，指定-m时无需指定）
            --output= 指定输出文件，不指定则自动生成一个文件（可选）
            --table= 指定表,多表用/隔开，默认全库（可选）
            --except= 排除指定表,多表用/隔开，默认无（可选）
            --type= insert/updete/delete/alter/drop/create,多类型用/隔开 指定操作类型（可选）
            --rollback 执行回滚操作，生成反向sql,row模式支持insert/delete/update，statement只支持回滚insert
            --start-datetime= 用于没有指定-m参数情况下，远程获取binlog的起始时间（可选）
            --stop-datetime= 用于没有指定-m参数情况下，远程获取binlog的结束时间 (可选)
            --rk 去除insert语句主键 (可选，默认不去除)
            --help 查看帮助
EOF;

        if (isset($param_arr['help'])) {
            $this->log($info . "\n", true);
        }

        $this->log("\033[0;33;1m" . $info . "\n");

        if (isset($param_arr['mysqlbinlog']) && $param_arr['mysqlbinlog']) {
            $this->mysqlbinlog = $param_arr['mysqlbinlog'];
        }

        if (!isset($param_arr['h'])) {
            $this->log("请使用-h指定数据库连接地址", true);
        }

        if (!isset($param_arr['u'])) {
            $this->log("请使用-u指定用户名", true);
        }

        if (!isset($param_arr['p'])) {
            $this->log("请使用-p指定密码", true);
        }

        if (!isset($param_arr['d'])) {
            $this->log("请使用-d指定数据库", true);
        }

        if (!isset($param_arr['output']) || (isset($param_arr['output']) && !$param_arr['output'])) {
            $param_arr['output'] = "analysis-" . date("Y-m-d H:i:s") . ".sql";
        }


        if (isset($param_arr['rollback'])) {
            //是否开启回滚SQL程序
            $this->rollback = true;
        }

        if (isset($param_arr['rk'])) {
            //是否去除主键
            $this->rk = true;
        }

        //指定输出文件
        $this->outFile = fopen($param_arr['output'], 'w+');

        $this->log("SQL文件保存路径:\e[0;31;1m" . $param_arr['output']);

        $this->database = $param_arr['d'];

        if (isset($param_arr['table']) && $param_arr['table']) {
            //指定表
            $this->searchTable = explode("/", strtolower($param_arr['table']));
        }

        if (isset($param_arr['except']) && $param_arr['except']) {
            //排除指定表
            $this->exceptTable = explode("/", strtolower($param_arr['except']));
        }

        if ($this->searchTable && $this->exceptTable) {
            //同时设置了，指定表和排除表，取两数组差集
            $this->searchTable = array_diff($this->searchTable, $this->exceptTable);
        }

        if (isset($param_arr['type'])) {
            //指定操作类型（insert、update、delete）
            $this->type = explode("/", strtoupper($param_arr['type']));
        }

        if (isset($param_arr['start-datetime']) && isset($param_arr['stop-datetime']) && $param_arr['start-datetime'] && $param_arr['stop-datetime']) {
            //指定binlog 起始结束时间
            $this->startDatetTime = $param_arr['start-datetime'];
            $this->stopDateTime = $param_arr['stop-datetime'];
        }

        $this->file = isset($param_arr['m']) ? $param_arr['m'] : "";

        $this->log("指定操作类型:\033[0;31;1m" . ($this->type ? implode("/", $this->type) : "全部"));

        $this->log("指定表:\e[0;31;1m" . ($this->searchTable ? implode("/", $this->searchTable) : "全部"));

        $this->log("排除指定表:\e[0;31;1m" . ($this->exceptTable ? implode("/", $this->exceptTable) : "无"));

        $this->log("insert语句去除主键:\e[0;31;1m" . ($this->rk ? "去除" : "不去除"));

        $this->log("是否生成回滚SQL:\e[0;31;1m" . ($this->rollback ? "生成" : "不生成"));

        //连接数据库=》用于获取表结构
        $this->mysqlConnect($param_arr['h'], $param_arr['u'], $param_arr['p'], $param_arr['d'],
            isset($param_arr['port']) ? $param_arr['port'] : "3306");

        if (!file_exists($this->file)) {
            $this->log("文件不存在", true);
        }

        //获取所有行数
        $this->getAllLines();

    }

    /**
     * 连接数据库
     * @param $host
     * @param $user
     * @param $password
     * @param $dataBase
     * @param int $port
     */
    public function mysqlConnect($host, $user, $password, $dataBase, $port = 3306)
    {
        $this->mysqli = new mysqli($host, $user, $password, $dataBase, $port);

        if ($info = $this->mysqli->connect_errno) {
            $this->log($this->mysqli->connect_error, true);
        }

        $this->getBinlog($host, $user, $password, $dataBase, $port);
    }


    /**
     * 获取远程binlog
     * @param $host
     * @param $user
     * @param $password
     * @param $dataBase
     * @param $port
     */
    public function getBinlog($host, $user, $password, $dataBase, $port)
    {
        if (!is_dir("result")) {
            mkdir("result");
        }

        if (!$this->file) {
            if (!$this->mysqlbinlog) {
                $this->log("请指定mysqlbinlog绝对地址", true);
            }

            $info = $this->mysqli->query("show global variables like '%binlog_format%';");

            $binlog = $this->mysqli->query("show binary logs");

            if ($info && ($info = $info->fetch_object()) && $binlog && $binInfo = $binlog->fetch_all()) {
                $mode = $info->Value;


                if (!isset($binInfo[0][0])) {
                    $this->log("读取远程binlog 失败，请尝试手动拉取binlog日志，并使用-m指定文件开始解析", true);
                }

                $time = time();

                $shell = $this->mysqlbinlog . " --read-from-remote-server -h$host -u$user -p$password -P$port " . $binInfo[0][0];

                if (!in_array(strtolower($mode), array("mixed", "statement"))) {
                    //row模式
                    $shell .= " --base64-output=DECODE-ROWS -vv";
                }

                if ($this->startDatetTime) {
                    $shell .= " --start-datetime=\"" . strval($this->startDatetTime) . "\"";
                }

                if ($this->stopDateTime) {
                    $shell .= " --stop-datetime=\"" . strval($this->stopDateTime) . "\"";
                }

                $shell .= " --to-last-log --result-file=result/binlog.log";

                $this->log("开始获取远程binlog，起始log:" . $binInfo[0][0] . "\n指定开始时间:" . ($this->startDatetTime ? $this->startDatetTime : "无") . "\n指定结束时间:" . ($this->stopDateTime ? $this->stopDateTime : "无"));

                shell_exec($shell);

                $this->log("binlog获取完成,耗时:" . (time() - $time) . "s");

                if (file_exists("result/binlog.log") && filemtime("result/binlog.log") > $time) {
                    $this->file = "result/binlog.log";
                } else {
                    $this->log("读取远程binlog 失败，请尝试手动拉取binlog日志，并使用-m指定文件开始解析", true);
                }
            }
        }

    }

    /**
     * 分析获取SQL结构体
     * @return Generator
     */
    public function dealFile()
    {
        $inTable = false;
        $sql = '';
        $is_row = true;
        $database = "";
        $id = 0;

        foreach ($this->readFile() as $n => $line) {
            if ($is_row == true) {
                //row模式文件
                if (preg_match("/^#{3}\s+(INSERT INTO|UPDATE|DELETE FROM).*/", $line, $match)) {
                    //匹配到SQL，开始拼接
                    $sql = preg_replace("/^#{3}\s+/", '', $match[0]);
                    $inTable = true;
                } else {
                    if ($inTable && preg_match("/^(#{3}\s+|\s+|@\d+).*/", $line, $match)) {
                        $sql .= preg_replace("/^#{3}\s+/", ' ', $line);
                    } else {
                        if ($inTable) {
                            $sql = preg_replace("/\/\*(.*)+\*\//", '', $sql);

                            yield "row" => $sql;

                            $inTable = false;
                        }

                    }
                }
            }

            //非row模式
            if (preg_match("/^\s*SET INSERT_ID=\s*(\d+)/i", $line, $match)) {
                $id = $match[1];
            }

            if (preg_match("/^(\s*use\s*[`a-zA-Z\-\._]+)/i", $line, $match)) {
                $database = preg_replace("/^(\s*use\s*)/", '', $match[0]);
            }

            if (preg_match("/^\s*(INSERT INTO|UPDATE|DELETE FROM|ALTER TABLE|DROP|CREATE).*/i", $line, $match)) {
                $is_row = false;
                $inTable = true;
                if (preg_match("/^\s*(INSERT INTO)\s+([`\-_a-zA-Z\.]+)/i", $line, $sqlInfo)) {

                    //拼主键
                    $primaryKey = $this->getPrimaryKey($database . "." . $sqlInfo[2]);

                    if ($primaryKey && $id) {

                        $sql = preg_replace("/^(\s*INSERT\sINTO\s[`_\-a-zA-Z]+\s*\()/i", "$1 `$primaryKey`,",
                            $match[0]);

                        $sql = preg_replace("/(values?\s*\()/i", "$1 $id,", $sql);

                    }
                } else {
                    $sql = preg_replace("/\s*(\/\*).*$/", '', $match[0]);//去除注释
                }

            } else {
                if ($inTable && !$is_row && !preg_match("/^\/\*/", $line, $match)) {
                    $sql .= $line;
                } else {
                    if (!$is_row && $inTable) {
                        //非row模式SQL直接输出，不需要再次进行处理
                        if (preg_match("/(INSERT INTO|UPDATE|DELETE FROM|ALTER TABLE|DROP TABLE IF EXISTS|CREATE TABLE)\s+([`\-_a-zA-Z]+\.[`\-_a-zA-Z]+)/i",
                                $sql) || preg_match("/`" . $this->database . "`/", $sql)) {
                            yield "statement" => $sql;
                        } else {
                            yield "statement" => preg_replace("/^(INSERT INTO|UPDATE|DELETE FROM|ALTER TABLE|CREATE TABLE|DROP TABLE IF EXISTS)\s*([`a-zA-Z_\-]*)\s*/i",
                                "$1 " . $database . ".$2 ", $sql);
                        }


                        $inTable = false;

                        $is_row = true;
                    }
                }
            }
        }
    }

    /**
     * 根据row模式结构体生成SQL
     */
    public function analysisSql()
    {
        $this->log("正在解析binlog:" . $this->file);

        foreach ($this->dealFile() as $mode => $value) {
            if (preg_match("/(INSERT INTO|UPDATE|DELETE FROM|ALTER TABLE|DROP TABLE IF EXISTS|CREATE TABLE)\s+([`\-_a-zA-Z\.]+)/i",
                $value, $match)) {
                $table = $match[2];

                if (preg_match("/(ALTER TABLE|DROP TABLE|CREATE TABLE)\s+([`\-_a-zA-Z\.]+)/i", $value)) {
                    if (!preg_match("/`" . $this->database . "`/", $table)) {
                        continue;
                    }
                } else {
                    $fields = $this->getTableFields($table);

                    if (!$fields) {
                        continue;
                    }
                }

                $sql = '';

                if (preg_match("/^(INSERT INTO)\s+([_\-`a-zA-Z\.]+)/i", $value)) {
                    if ($this->type && !in_array("INSERT", $this->type)) {
                        continue;
                    }

                    if ($mode === "statement") {
                        $this->out($mode, $value);
                        continue;
                    }
                    //插入语句
                    $val = "(" . implode(",", $fields) . ")";

                    $sql = preg_replace("/^(INSERT INTO)\s+([_\-`a-zA-Z\.]+)\s+(SET)/", "$1 $2 $val", $value);

                    if (preg_match_all("/@\d+=/", $sql, $match)) {
                        foreach ($match[0] as $key => $v) {

                            if ($key === 0) {
                                $sql = str_replace($v, 'values(', $sql);
                            } elseif ($key == count($match[0]) - 1) {
                                $sql = preg_replace("/$v(.*)/", ",$1)", $sql);
                            } else {
                                $sql = str_replace($v, ',', $sql);
                            }
                        }
                    }

                } elseif (preg_match("/^(UPDATE)\s+([_\-`a-zA-Z\.]+)/i", $value)) {
                    if ($this->type && !in_array("UPDATE", $this->type)) {
                        continue;
                    }

                    if ($mode === "statement") {
                        $this->out($mode, $value);
                        continue;
                    }

                    if(preg_match("/^UPDATE\s+.*WHERE(.|\s)*\s*SET/i",$value)){
                        $value = preg_replace("/^(UPDATE\s+.*)(WHERE(.|\s)*)(SET(.|\s)*)/i","$1 $4 $2",$value);
                    }

//                    $value = preg_replace("/\s/",' ',$value);
                    //更新
                    $where = explode(" WHERE", $value);

                    foreach ($where as $k => $vv) {
                        if (preg_match_all("/\s+@\d+/", $vv, $match)) {
                            if ($k === 0) {
                                //SET语句
                                foreach ($match[0] as $key => $v) {
                                    if ($key === 0) {
                                        $sql = preg_replace("/$v=/", " " . $fields[trim($v)] . "=",
                                            str_replace("WHERE", "SET", $vv));
                                    } else {
                                        $sql = preg_replace("/$v=/", "," . $fields[trim($v)] . "=", $sql);
                                    }
                                }
                            } else {
                                //where 替换@ 增加 AND
                                foreach ($match[0] as $key => $v) {
                                    if ($key === 0) {
                                        $sql .= preg_replace("/$v=/", " WHERE " . $fields[trim($v)] . "=", $vv);
                                    } else {
                                        $sql = preg_replace("/$v=/", " AND " . $fields[trim($v)] . "=", $sql);
                                    }
                                }
                            }
                        }
                    }

                } elseif (preg_match("/^(DELETE FROM)\s+([_\-`a-zA-Z\.]+)/i", $value)) {
                    if ($this->type && !in_array("DELETE", $this->type)) {
                        continue;
                    }

                    if ($mode === "statement") {
                        $this->out($mode, $value);
                        continue;
                    }
                    //删除
                    if (preg_match_all("/\s+@\d+/", $value, $match)) {
                        foreach ($match[0] as $key => $v) {
                            if ($key === 0) {
                                $sql = preg_replace("/$v=/", " " . $fields[trim($v)] . "=", $value);
                            } else {
                                $sql = preg_replace("/$v=/", " AND " . $fields[trim($v)] . "=", $sql);
                            }
                        }
                    }
                } elseif (preg_match("/^(ALTER TABLE)\s+([_\-`a-zA-Z\.]+)/i", $value)) {
                    //表结构更改
                    if ($this->type && !in_array("ALTER", $this->type)) {
                        continue;
                    }

                    $sql = $value;
                } elseif (preg_match("/^(DROP TABLE)\s+([_\-`a-zA-Z\.]+)\s*$/i", $value)) {
                    //表结构更改
                    if ($this->type && !in_array("DROP", $this->type)) {
                        continue;
                    }

                    $sql = $value;
                } elseif (preg_match("/^(CREATE TABLE)\s+([_\-`a-zA-Z\.]+)/i", $value)) {
                    //表结构更改
                    if ($this->type && !in_array("CREATE", $this->type)) {
                        continue;
                    }

                    $sql = $value;
                }

                if ($sql) {
                    $this->out($mode, $sql);
                }
            }
        }
    }

    /**
     * 写入SQL文件
     */
    public function out($mode, $sql)
    {
        $sql = preg_replace("/\n/", ' ', $sql);

        if (!$this->rollback && $this->rk && preg_match("/^(INSERT INTO)\s+([_\-`a-zA-Z\.]+)/i", $sql, $match)) {
            //去除主键
            preg_match_all("/(?<=\()[^\)]+/", $sql, $field_value);

            if (isset($field_value[0][0]) && isset($field_value[0][1])) {
                $field = explode(",", $field_value[0][0]);
                $value = explode(",", $field_value[0][1]);

                if (count($field) === count($value)) {

                    $primaryKey = $this->getPrimaryKey($match[2]);

                    foreach ($field as $key => $v) {
                        if ($primaryKey && preg_match("/^\s*`$primaryKey/i", $v)) {
                            unset($field[$key]);
                            unset($value[$key]);
                            break;
                        }
                    }

                    $sql = "insert into " . $match[2] . " (" . implode(",", $field) . ") values(" . implode(",",
                            $value) . ")";
                }
            }
        }

        if ($this->rollback) {
            //回滚SQL
            $sql = $this->backSql($mode, $sql);
        }

        if ($sql) {
            $this->sqlNums++;

            fwrite($this->outFile, $sql . ";" . PHP_EOL);
        }
    }

    /**
     * 生成回滚SQL
     * @param $mode
     * @param $sql
     * @return string
     */
    public function backSql($mode, $sql)
    {
        $roll_sql = "";
        if (preg_match("/^(INSERT INTO)\s+([_\-`a-zA-Z\.]+)/i", $sql, $match)) {
            //insert 语句=》改为delete语句
            preg_match_all("/(?<=\()[^\)]+/", $sql, $field_value);

            if (isset($field_value[0][0]) && isset($field_value[0][1])) {
                $field = explode(",", $field_value[0][0]);
                $value = explode(",", $field_value[0][1]);

                if (count($field) !== count($value)) {
                    return '';
                }

                $where = "where ";

                $primaryKey = $this->getPrimaryKey($match[2]);

                foreach ($field as $key => $v) {
                    if ($primaryKey && preg_match("/^\s*`$primaryKey/i", $v)) {
                        $where .= $v . "=" . $value[$key];
                        break;
                    } else {
                        $where .= $v . "=" . $value[$key] . " AND ";
                    }
                }

                $where = rtrim($where, " AND ");

                $roll_sql = "delete from " . $match[2] . " $where";
            }
        } elseif (preg_match("/^(UPDATE)\s+([_\-`a-zA-Z\.]+)/i", $sql, $match)) {
            if ($mode == "row") {
                $set_p = stripos($sql, " set ");

                $where_p = strripos($sql, " where ");

                $set_string = preg_replace("/^\s*SET/i", " WHERE", substr($sql, $set_p, $where_p - $set_p - 1));
                $set_string = preg_replace("/,`/i", " AND `", $set_string);

                $where_string = preg_replace("/^\s*WHERE/i", " SET", substr($sql, $where_p));
                $where_string = preg_replace("/AND\s*`/i", ",`", $where_string);

                $roll_sql = $match[0] . $where_string . $set_string;
            }

        } elseif (preg_match("/^(DELETE FROM)\s+([_\-`a-zA-Z\.]+)/i", $sql, $match)) {

            if ($mode === "row") {
                $where_p = stripos($sql, " where ");

                $where_string = preg_replace("/^\s*WHERE/i", "", substr($sql, $where_p));

                $array = preg_split("/\s+AND\s+/i", $where_string);

                $values = "values (";
                $fields = "(";
                array_map(function ($value) use (&$values, &$fields) {
                    $filed = substr($value, 0, stripos($value, "="));
                    $v = substr($value, stripos($value, "=") + 1);
                    $values .= $v . ",";
                    $fields .= $filed . ",";
                }, $array);

                $values = trim($values, ",") . ")";

                $fields = trim($fields, ",") . ")";


                $roll_sql = "insert into " . $match[2] . " " . $fields . " " . $values;
            }
        }

        return $roll_sql;
    }

    /**
     * 获取指定表结构
     * @param $table
     * @return mixed
     */
    public function getTableFields($table)
    {

        if (!preg_match("/`" . $this->database . "`/", $table)) {
            return [];
        }

        $real_table = strtolower(preg_replace("/(" . $this->database . "|`|\.)/", '', $table));

        if ($this->searchTable && !in_array($real_table, $this->searchTable)) {
            //对特定表进行排查
            return [];
        }

        if ($this->exceptTable && in_array($real_table, $this->exceptTable)) {
            //对特定表进行排除
            return [];
        }

        if (isset($this->table[$table])) {
            return $this->table[$table];
        }

        if ($result = $this->mysqli->query("show FULL FIELDS from $table")) {
            $result = $result->fetch_all();
        } else {
            return [];
        }

        foreach ($result as $k => $value) {
            $this->table[$table]['@' . ($k + 1)] = "`" . $value[0] . "`";
        }

        return $this->table[$table];
    }

    /**
     * 获取表主键
     * @param $table
     * @return string
     */
    public function getPrimaryKey($table)
    {
        if (!preg_match("/`" . $this->database . "`/", $table)) {
            return '';
        }

        $real_table = strtolower(preg_replace("/(" . $this->database . "|`|\.)/", '', $table));

        if ($this->searchTable && !in_array($real_table, $this->searchTable)) {
            //对特定表进行排查
            return '';
        }

        if ($this->exceptTable && in_array($real_table, $this->exceptTable)) {
            //对特定表进行排除
            return '';
        }

        if (isset($this->primaryKey[$table])) {
            return $this->primaryKey[$table];
        }

        if ($result = $this->mysqli->query("show keys from $table where key_name='PRIMARY'")) {
            $result = $result->fetch_all();

            if (!empty($result)) {
                $this->primaryKey[$table] = $result[0][4];
            }
        } else {
            return "";
        }

        return $this->primaryKey[$table];
    }

    /**
     * 读取文件
     * @param int $key
     * @return Generator
     */
    public function readFile($key = 1)
    {
        $handle = fopen($this->file, 'r');

        while (feof($handle) === false) {
            $this->progress($key);
            $key++;
            yield fgets($handle);
        }

        fclose($handle);
    }

    /**
     * 获取总行数
     */
    public function getAllLines()
    {
        $this->startTime = time();
        $handle = fopen($this->file, 'r');
        $line = 0;

        $this->log("开始统计行数");
        while (feof($handle) === false) {
            fgets($handle);
            $line++;
        }

        fclose($handle);

        $this->lines = $line;

        $this->log("统计完成，总行数:$line");
    }

    /**
     * 日志
     * @param $info
     * @param bool $die
     */
    public function log($info, $die = false)
    {
        if ($die) {
            echo "\033[0;31m\n提示:" . $info . "\033[0m" . PHP_EOL;
            exit("程序终止" . PHP_EOL);
        } else {
            echo "\033[0;32m$info\033[0m" . PHP_EOL;
        }
    }

    //进度条
    public function progress($key)
    {
        $kb = memory_get_usage() / 1024;

        $kb = round(($kb > 1024 ? $kb / 1024 : $kb), 2) . ($kb > 1024 ? "MB" : "KB");

        $max_kb = memory_get_peak_usage() / 1024;

        $max_kb = round(($max_kb > 1024 ? $max_kb / 1024 : $max_kb), 2) . ($max_kb > 1024 ? "MB" : "KB");

        $time = time() - $this->startTime;

        $time = $time > 60 ? (floor($time / 60) . "min" . ($time - floor($time / 60) * 60) . "s") : $time . "s";

        printf("\033[1;33mprogress: [%-50s] %d%% Done;time:%s;sql nums:%d;Memory usage：%s;Memory max usage:%s\r\033[0m",
            str_repeat('>', $key / ($this->lines) * 50), $key / ($this->lines) * 100, $time, $this->sqlNums, $kb,
            $max_kb);
    }

    /**
     * 销毁资源
     */
    public function __destruct()
    {
        // TODO: Implement __destruct() method.

        if ($this->outFile) {
            fclose($this->outFile);
        }

        if ($this->mysqli) {
            $this->mysqli->close();
        }

        $this->log("\e[0;31;1m执行结束");
    }
}

$mysql = new mysql_analysis();

$mysql->analysisSql();

unset($mysql);


