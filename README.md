# binlog-analysis
基于php，用于解析本地或远程mysqlbinlog获得具体SQL

之前自己遇到了数据库恢复的问题，但是水平有限基于row模式的binlog得到日志中的伪SQL，无法执行，因此萌发出了自己编写一个解析类的想法，花了点时间用PHP写了这个类，自己测试没什么问题 适配了各种数据库模式，支持本地解析以及远程解析，执行起来也很简单，远程解析注意放行shell_exec函数就好

```php
example1(本地解析示例): php mysql_analysis.php -h"10.0.108.58" -ubee -dbee -pzxzdapp666 -maa.txt --output=aa.sql --table=wallet/user_role/jobs --except=wallet/user_role --port=3306

example2(远程解析示例):php mysql_analysis.php -h"10.0.108.58" -ubee -dbee-master -pzxzdapp666 --output=drop.sql --start-datetime="2019-11-29 00:00:00" --stop-datetime="2019-11-29 17:00:00" --except=jobs --mysqlbinlog=/usr/local/mysql-5.7.25-macos10.14-x86_64/bin/mysqlbinlog
```

```
 注：此脚本不指定-m情况下，需要执行shell_exec函数，请注意放行
            -m 指定binlog解析出的分析文件(mysqlbinlog mysql-bin.* --base64-output=decode-rows -vv >> xxxx.txt 解析后的文件，目前支持row/statement模式，理论支持MIXED)，不指定-m则表示使用远程获取
            -h 指定数据库连接地址
            -u 指定用户名
            -p 指定数据库密码
            -d 指定数据库
            --port= 指定数据库端口 可选
            --mysqlbinlog 指定mysql目录下mysqlbinlog绝对地址，不是binlog日志文件，类似/www/server/mysql/bin/mysqlbinlog（远程获取binlog时必选，指定-m时无需指定）
            --output= 指定输出文件，不指定则自动生成一个文件（可选）
            --table= 指定表,多表用/隔开，默认全库（可选）
            --except= 排除指定表,多表用/隔开，默认无（可选）
            --type= insert/updete/delete/alter/drop/create,多类型用/隔开 指定操作类型（可选）
            --rollback 执行回滚操作，生成反向sql,row模式支持insert/delete/update，statement只支持回滚insert
            --start-datetime= 用于没有指定-m参数情况下，远程获取binlog的起始时间（可选）
            --stop-datetime= 用于没有指定-m参数情况下，远程获取binlog的结束时间 (可选)
            --rk 去除insert语句主键 (可选，默认不去除)
            --help 查看帮助
```