<?php
if (!defined('WORKFLOW_BASE_PATH')) {
    if (ISDEV) {
        define('WORKFLOW_BASE_PATH', '/data/workflow/');
    } else {
        define('WORKFLOW_BASE_PATH', '/data/www/workflow/');
    }
}
require_once 'user.php';
require_once 'workspace_path.php';

vendor('mycache');
vendor('redis/redis_client');
vendor('kmapi_client');
vendor('switches');
vendor('curl_helper');
uses('cake_log');


date_default_timezone_set('Asia/Shanghai');		//设置时区

require_once(APP . DS . 'libs' .DS .'fields.php');
require_once(APP . DS . 'libs' .DS .'class_factory.php');
function g($class, $type=null, $params=null)
{
    if (!empty($type)) {
        $type = ucfirst($type);
    }

    if (isset($params['new_instance']) && $params['new_instance']) {
        $new_instance = true;
        unset($params['new_instance']);
    } else {
        $new_instance = false;
    }

    return ClassFactory::make($class, $type, $params, !$new_instance);
}

/**
 * tapd2是否外网版的判断方法
 *
 * @param string $env_type 版本类型
 * @author robertyang
 * @access public
 * @return boolean 是否外网版本
 */
function i($env_type = ENV_INTR)
{
    return ENV == $env_type;
}
function dconst($constant_name, $default='')
{
    if (!defined($constant_name)) {
        define($constant_name, $default);
    }
}
require_once 'tapd_constant.php';		//引入常量定义文件
ClassFactory::import('CacheProvider');	//全局加载缓存
ClassFactory::import('GrayReleaseService', 'Service');

/**
 * 设置页面缓存的映射关系
 *
 * @package Config
 * @author spark
 */
CacheMapper::map(array(
    'workitems' => array('{$project_id}_prong_iterations',
                        '{$project_id}_prong_iterations_view', ),
    'workitem_tasks' => array('{$project_id}_prong_iterations',
                              '{$project_id}_prong_iterations_view',),
    'workitem_stories' => array('{$project_id}_prong_iterations',
                                '{$project_id}_prong_iterations_view',),
    'prong_iterations' => array('{$project_id}_prong_iterations',
                                '{$project_id}_prong_iterations_view',),
    'views' => array('{$form["workspace_code"]}_prong_iterations', ),
    'user_views' => array('{$form["workspace_code"]}_prong_iterations', ),
));

 function stories_tasks_date_cmp($a, $b)
 {
     $a = isset($a['Story']) ? $a['Story'] : $a['Task'];
     $b = isset($b['Story']) ? $b['Story'] : $b['Task'];
       
     return ($a['begin'] < $b['begin']) ? -1 : 1;
 }


/**
 * 字符串清理类
 *
 * @package Config
 * @author robertyang
 */
class MySanitize
{
    /**
     * Removes any non-alphanumeric characters.
     *
     * @param string $string 需要清理的字符串
     * @param array $allowed 允许写入的关键词
     * @return string
     * @access public
     */
    public function paranoid($string, $allowed = array())
    {
        $allow = null;
        if (!empty($allowed)) {
            foreach ($allowed as $value) {
                $allow .= "\\$value";
            }
        }

        if (is_array($string)) {
            foreach ($string as $key => $clean) {
                $cleaned[$key] = preg_replace("/[^{$allow}a-zA-Z0-9]/", "", $clean);
            }
        } else {
            $cleaned = preg_replace("/[^{$allow}a-zA-Z0-9]/", "", $string);
        }
        return $cleaned;
    }
    /**
     * Makes a string SQL-safe by adding slashes (if needed).
     *
     * @param string $string 需要被清理的字符串
     * @return string
     * @access public
     */
    public function sql($string)
    {
        if (!ini_get('magic_quotes_gpc')) {
            $string = addslashes($string);
        }
        return $string;
    }
    /**
     * Returns given string safe for display as HTML. Renders entities.
     *
     * @param string $string 需要被清理的字符串
     * @param boolean $remove If true, the string is stripped of all HTML tags
     * @return string
     * @access public
     */
    public function html($string, $remove = false, $remove_nl = false)
    {
        if (is_array($string)) {
            return $string;
        }
        $special_chars = $remove_nl ? '/[\x00-\x01\x0A-\x0D]/' : '/[\x00-\x01\x0B-\x0D]/';
        if ($remove) {
            //$string = strip_tags($string);
            $patterns = array("/&amp;/", "/&#37;/", "/&lt;/", "/&gt;/", "/&quot;/", "/&#39;/", "/&#40;/", "/&#41;/", "/&#43;/", $special_chars);//, "&#45;"
            $replacements = array("&", "%", "<", ">", '"', "'", "(", ")", "+", '');//, "/-/"
            $string = preg_replace($patterns, $replacements, $string);
        } else {
            $patterns = array("/\&/", "/%/", "/</", "/>/", '/"/', "/'/", "/\\\\/", $special_chars);//, "/-/"
            $replacements = array("&amp;", "&#37;", "&lt;", "&gt;", "&quot;", "&#39;", "&#92;", '');//, "&#45;"
            $string = preg_replace($patterns, $replacements, $string);
        }
        return $string;
    }

    public function html_with_br($string, $remove = false, $remove_nl = false)
    {
        $string = preg_replace('/<br\s*\/{0,1}>/i', 'tapd_br_replace_token', $string);
        $new_string = $this->html($string, $remove, $remove_nl);
        return str_replace('tapd_br_replace_token', '<br/>', $new_string);
    }

    /**
     * Recursively sanitizes given array of data for safe input.
     *
     * @param mixed &$toClean 需要被清理的字符串数组
     * @return mixed
     * @access public
     */
    public function cleanArray(&$toClean)
    {
        return $this->cleanArrayR($toClean);
    }
    /**
     * Method used for recursively sanitizing arrays of data
     * for safe input
     *
     * @param array &$toClean 被清理的字符串数组
     * @return array The clean array
     * @access public
     */
    public function cleanArrayR(&$toClean)
    {
        if (is_array($toClean)) {
            while (list($k, $v) = each($toClean)) {
                if (is_array($toClean[$k])) {
                    $toClean[$k] = $this->cleanArray($toClean[$k]);
                } else {
                    $toClean[$k] = $this->cleanValue($v);
                }
            }
            return $toClean;
        } else {
            return null;
        }
    }

    /**
     * Method used by cleanArray() to sanitize array nodes.
     *
     * @param string $val 需要被清理的字符串
     * @return string
     * @access public
     */
    public function cleanValue($val)
    {
        if ("" === $val) {
            return "";
        }
        //Replace odd spaces with safe ones
        $val = str_replace(" ", " ", $val);
        $val = str_replace(chr(0xCA), "", $val);
        //Encode any HTML to entities.
        $val = $this->html($val);
        //Double-check special chars and replace carriage returns with new lines
        $val = preg_replace("/\\\$/", "$", $val);
        $val = preg_replace("/\r\n/", "\n", $val);
        $val = str_replace("!", "!", $val);
        $val = str_replace("'", "'", $val);
        //Allow unicode (?)
        $val = preg_replace("/&amp;#([0-9]+);/s", "&#\\1;", $val);
        //Add slashes for SQL
        $val = $this->sql($val);
        //Swap user-inputted backslashes (?)
        $val = preg_replace("/\\\(?!&amp;#|\?#)/", "\\", $val);
        return $val;
    }

    /**
     * Formats column data from definition in DBO's $columns array
     *
     * @param object &$model The model containing the data to be formatted
     * @return void
     * @access public
     */
    public function formatColumns(&$model)
    {
        foreach ($model->data as $name => $values) {
            if ($name == $model->name) {
                $curModel =& $model;
            } elseif (isset($model->{$name}) && is_object($model->{$name}) && is_subclass_of($model->{$name}, 'Model')) {
                $curModel =& $model->{$name};
            } else {
                $curModel = null;
            }

            if ($curModel != null) {
                foreach ($values as $column => $data) {
                    $colType = $curModel->getColumnType($column);

                    if ($colType != null) {
                        $db =& ConnectionManager::getDataSource($curModel->useDbConfig);
                        $colData = $db->columns[$colType];

                        if (isset($colData['limit']) && strlen(strval($data)) > $colData['limit']) {
                            $data = substr(strval($data), 0, $colData['limit']);
                        }

                        if (isset($colData['formatter']) || isset($colData['format'])) {
                            switch (strtolower($colData['formatter'])) {
                                case 'date':
                                    $data = date($colData['format'], strtotime($data));
                                break;
                                case 'sprintf':
                                    $data = sprintf($colData['format'], $data);
                                break;
                                case 'intval':
                                    $data = intval($data);
                                break;
                                case 'floatval':
                                    $data = floatval($data);
                                break;
                            }
                        }
                        $model->data[$name][$column]=$data;
                    }
                }
            }
        }
    }
}
global $clean;
$clean=new MySanitize();
if (extension_loaded('secapi_xss')) {
    global $SECAPI_CONFIG;
    if (defined("IS_EXTERNAL") && IS_EXTERNAL) {
        $secapi_config_handle = fopen(ROOT . DS . APP_DIR . DS . 'config' . DS . 'secapi_external.conf', 'rb');
    } else {
        $secapi_config_handle = fopen(ROOT . DS . APP_DIR . DS . 'config' . DS . 'secapi.conf', 'rb');
    }
    $SECAPI_CONFIG = fread($secapi_config_handle, 4096*2);
    fclose($secapi_config_handle);
}
/**
 * 二次封装的字符串清理类
 *
 * @package Config
 * @author robertyang
 */
class MyClean
{
    /**
     * id只允许是数字,以前该方法允许id为字符串
     *
     * @param string $string 需要被清理的字符串
     * @param array $allowed 允许接受的字符串数组
     * @author robertyang
     * @return string 清理后的字符串
     */
    public static function paranoid($string, $allowed = array())
    {
        $allow = null;
        if (!empty($allowed)) {
            foreach ($allowed as $value) {
                $allow .= "\\$value";
            }
        }

        if (is_array($string)) {
            $cleaned = array();
            foreach ($string as $key => $clean) {
                $flag = '';
                if (strpos($clean, '-') === 0 && strpos($allow, '-') === false) {
                    $flag = '-';
                }
                $cleaned[$key] = $flag . preg_replace("/[^{$allow}0-9]/", "", $clean);
            }
        } else {
            $flag = '';
            if (strpos($string, '-') === 0 && strpos($allow, '-') === false) {
                $flag = '-';
            }
            $cleaned = $flag . preg_replace("/[^{$allow}0-9]/", "", $string);
        }
        return $cleaned;
    }

    /**
     * 字符串清理
     *
     * @param string $value 需要被清理的字符串
     * @author robertyang
     * @return string 清理后的字符串
     */
    public static function cleanValue($value)
    {
        global $clean;
        return $clean->cleanValue($value);
    }

    /**
     * Returns given string safe for display as HTML. Renders entities.
     *
     * @param string $string 需要被清理的字符串
     * @param boolean $remove If true, the string is stripped of all HTML tags
     * @return string
     * @access public
     */
    public static function html($value, $remove = false)
    {
        global $clean;
        return $clean->html($value, $remove);
    }

    public static function inline_attr_html($value, $remove = false)
    {
        global $clean;
        return $clean->html($clean->html($value, $remove));
    }

    public static function html_with_br($value, $remove = false)
    {
        global $clean;
        return $clean->html_with_br($value, $remove);
    }

    /**
     * 字符串数组清理
     *
     * @param array &$data 需要被清理的字符串数组
     * @author robertyang
     * @return array 清理后的字符串数组
     */
    public static function cleanArray(&$data)
    {
        global $clean;
        $clean->cleanArray($data);
        return $data;
    }

    /**
     * 转义字符串中的emoji表情和特殊字符
     * @param $str
     * @return mixed|string
     */
    public static function encodeEmoji($str)
    {
        return $str;
        if (!is_string($str)) {
            return $str;
        }
        if (!$str || $str == 'undefined') {
            return '';
        }
        $text = json_encode($str); //暴露出unicode
        $text = preg_replace_callback("/(\\\u[ed][0-9a-f]{3})/i", function ($str) {
            return addslashes($str[0]);
        }, $text);
        return json_decode($text);
    }

    /**
     * 还原字符串中的emoji表情和特殊字符
     * @param $str
     * @return mixed
     */
    public static function decodeEmoji($str)
    {
        return $str;
        $text = json_encode($str); //暴露出unicode
        $text = preg_replace_callback('/\\\\\\\\/i', function ($str) {
            return '\\';
        }, $text); //将两条斜杠变成一条，其他不动
        return json_decode($text);
    }

    /**
     * sql清理
     *
     * @param string $string 需要被清理的sql
     * @author robertyang
     * @return string 清理后的sql
     */
    public static function sql($string)
    {
        global $clean;
        return $clean->sql($string);
    }

    public static function clean_url_xss($from_url)
    {
        $url_parsed = parse_url($from_url);
        if (isset($url_parsed['query'])) {
            $new_params_array = $params_array = array();
            parse_str($url_parsed['query'], $params_array);
            foreach ($params_array as $key => $value) {
                $new_params_array[self::html($key)] = self::html($value);
            }
            $url_parsed['query'] = http_build_query($new_params_array);
        }
        $url = unparse_url($url_parsed);
        # var_dump($from_url, $url);

        return $url;
    }

    /**
     * 将数组中的各种参数进行检测，过滤掉html标签防止跨站脚本攻击
     *
     * @param mixed &$mixed 需要被清理的字符串或字符串数组
     * @author joeyue
     * @return mixed 清理后的sql
     */
    public static function cleanXSS(&$mixed, $remove = false)
    {
        //默认将处理掉参数中的js代码
        if (is_array($mixed)) {
            foreach ($mixed as $key=>&$value) {
                MyClean::cleanXSS($value, $remove);
            }
        } else {
            global $clean;
            $mixed = $clean->html($mixed, $remove);
        }

        return $mixed;
    }

    public static function cleanXSSWithKey($mixed, $remove = false)
    {
        $mix = array();
        //默认将处理掉参数中的js代码
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mix[MyClean::cleanXSSWithKey($key, $remove)] = MyClean::cleanXSSWithKey($value, $remove);
            }
        } else {
            global $clean;
            $mix = $clean->html($mixed, $remove);
        }
        return $mix;
    }

    /**
     * 清除ref带来的跳转攻击
     *
     * @param string $ref_url 需要被清理的return url
     * @author joeyue
     * @return string 清理后的return url
     */
    public static function cleanJUMP($ref_url)
    {
        if (ISDEV) {
            $jump_scope = array(
                    TAPD_DOMAIN,
                    'lion.oa.com',
                    'tiger.oa.com',
                );
        } else {
            //正式环境
            if (IS_CLOUD) {
                //私有化及云端
                $jump_scope = array(DOMAIN);
                if (!IS_PRIVATE) {
                    $jump_scope[] = 'stat.tapd.cn';
                }
            } else {
                //内外网
                $jump_scope = array(
                            TAPD_DOMAIN,
                            DOMAIN,
                            'tapd.tencent.com',
                            'om.tencent.com',
                            'tapd.oa.com',
                        );
            }
        }
        
        if (IS_CLOUD && !ISDEV) {
            $jump_scope[] = VPCConfig::site_path();
            // $jump_scope = array_unique($jump_scope);
        }

        $can_jump = str_begin_with($ref_url, BASE_PATH);
        if (!$can_jump) {
            foreach ($jump_scope as $domain) {
                if (str_begin_with($ref_url, "http://{$domain}") || str_begin_with($ref_url, "https://{$domain}")) {
                    $can_jump = true;
                    break;
                }
            }
        }

        return $can_jump ? $ref_url : BASE_PATH;
    }

    /**
     * 清除富文本中的危险标签
     *
     * @param string $richText 需要处理的富文本
     * @return string  处理后的富文本
     */
    public static function richText($richText)
    {
        //替换由chrome浏览器拖动图片导致的图片路径变成全路径的问题
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $http_origin = $_SERVER['HTTP_ORIGIN'];
            $richText = preg_replace('#<img src="' . $http_origin . '/tfl/#', '<img src="/tfl/', $richText);
        }
        if (extension_loaded('secapi_xss')) {
            global $SECAPI_CONFIG;
            return SECAPI_AntiXss::FilterAllActiveContent($SECAPI_CONFIG, $richText);
        } else {
            return $richText;
        }
    }

    /**
     * 清除文本中的 4 bytes 的字符
     *
     * @param string $text 需要处理的文本
     * @return string  处理后的文本
     */
    public static function cleanUTF8mb4($text)
    {
        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /**
     * 清除文本中的 backspace控制字符，在浏览器下显示为【黑点】
     *
     */
    public static function cleanBackspaces($text)
    {
        return preg_replace('/[\x08]/u', '', $text);
    }
    /**
     * 清除人名中不合法的部分
     *
     * @param string $nick 需要处理的人名
     * @return string  处理后的文本
     */
    public static function cleanNick($nick)
    {
        return preg_replace('/\([^\)]+\)/', '', $nick);
    }

    /**
     * 清除富文本中的标签，并把换行变成\\n（注意，不是换行符，
     * 而是换行符的字面量），供需求评论流转模板用
     *
     * @param string $richText 需要处理的富文本
     * @return string  处理后的文本
     */
    public static function rich2text($richText)
    {
        $new_line_tags = array('</p>','<br>', '<br/>', '</div>');
        $ret = str_ireplace($new_line_tags, '\\n', $richText);
        $ret = strip_tags($ret);
        return $ret;
    }

    /**
     * 用反斜杠对Javascript特殊字符进行转译，以便输出到<script>标签内
     *
     * @param string $text 需要处理的文本
     * @return string  处理后的文本
     */
    public static function clean4JS($text)
    {
        $special_chars = array("'",'"', '\\');
        $ret = str_replace($special_chars, '\n', $text);
        return $ret;
    }

    /*
     * 去除当只有一个人名时显示在人名后的分号
     * @param string $name_string 一个用分号隔开的人名串
     * @author domaintian
     */
    public static function clean_user_name($name_string = '')
    {
        $name_string = trim($name_string);
        if (!empty($name_string)) {
            $name_string = pretreat_items_list($name_string, ';', true);
        }
        return $name_string;
    }

    public static function jsString($str, $no_wrap=false)
    {
        if ($no_wrap) {
            $str = str_replace(PHP_EOL, '', $str);
        }
        return addcslashes($str, '\\\'"<>');
    }

    public static function safeCakeLikeCondition($str, $special_escape_list='%_\\')
    {
        return addcslashes($str, $special_escape_list);
    }

    public static function sqlString($str, $special_escape_list='%_')
    {
        return addcslashes(mysql_escape_string($str), $special_escape_list);
    }

    /**
     * 过滤url_cache_key,query_token之类的a-z0-9结构的字符串，防止拼接回显注入漏洞
     * @param  string $str
     * @author Erikyang
     */
    public static function cleanToken($str)
    {
        if (empty($str) || !is_string($str)) {
            return $str;
        }
        return preg_replace('/[^0-9a-z]/', '', $str);
    }

    public static function cleanSqlParam($str)
    {
        if (empty($str) || !is_string($str)) {
            return $str;
        }
        return preg_replace('/[^a-zA-Z0-9_]/', '', $str);
    }

    /**
     * 过滤 html id 的取值（只允许大小写数字、_、-）
     *
     * @param  [type] $str [description]
     * @return [type]      [description]
     */
    public static function cleanHtmlId($str)
    {
        if (empty($str) || !is_string($str)) {
            return $str;
        }
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $str);
    }

    public static function cleanFieldAndTableName($str)
    {
        return self::cleanSqlParam($str);
    }

    public static function cleanLikeConditionStr($str)
    {
        return self::sqlString($str);
    }

    public static function cleanEqualConditionStr($str)
    {
        return self::sql($str);
    }

    public static function cleanFileName($str)
    {
        if (empty($str) || !is_string($str)) {
            return $str;
        }
        $str = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $str);
        $str = preg_replace('/\.+/', '.', $str);
        return $str;
    }

    public static function cleanFilePath($str)
    {
        if (empty($str) || !is_string($str)) {
            return $str;
        }
        $dirs = explode('/', $str);
        $cleaned_dirs = [];
        foreach ($dirs as $dir) {
            $dir = MyClean::cleanFilename($dir);
            $cleaned_dirs[] = $dir;
        }
        unset($dirs, $dir);
        return implode('/', $cleaned_dirs);
    }
}


    /**
     * 获取人员列表中的第一个人，在bug的my_view中用到
     *
     * @param string $original_owners 用户名字序列
     * @return string $owner 第一个用户
     * @author markguo
     */
    function get_first_owner($original_owners)
    {
        $owners = explode(";", $original_owners);
        if (0 === count($owners)) {
            return "";
        }
        foreach ($owners as $k => $v) {
            if ($v == "") {
                unset($owners[$k]);
            }
        }
        if (0 === count($owners)) {
            return "";
        };
        if (1 == count($owners)) {
            return $owners[0];
        } else {
            return $owners[0] . ";";
        }
    }

    /**
    * 获得客户端ip，主要用于wiki、附件中
    *
    * @author	robertyang
    * @version	1.0
    * @access	public
    * @package	tapd_client
    * @return	string	ip
    */
    function client_ip()
    {
        if (getenv("HTTP_CLIENT_IP") && strcasecmp(getenv("HTTP_CLIENT_IP"), UNKNOWN)) {
            //如果在环境变量中定义了客户端IP，且不为'unknown'
            $ip = getenv("HTTP_CLIENT_IP");
        } elseif (getenv("HTTP_X_FORWARDED_FOR") && strcasecmp(getenv("HTTP_X_FORWARDED_FOR"), UNKNOWN)) {
            //如果在环境变量中定义了重定向之前的IP，且不为'unknown'
            $ip = getenv("HTTP_X_FORWARDED_FOR");
            $ip_arr = explode(',', $ip);
            $ip = trim($ip_arr[0]);
        } elseif (getenv("HTTP_X_REAL_IP") && strcasecmp(getenv("HTTP_X_REAL_IP"), UNKNOWN)) {
            //如果在环境变量中定义了最基本的IP，且不为'unknown'
            $ip = getenv("HTTP_X_REAL_IP");
        } elseif (getenv("REMOTE_ADDR") && strcasecmp(getenv("REMOTE_ADDR"), UNKNOWN)) {
            //如果在_SERVER变量中定义了最基本的IP，且不为'unknown'
            $ip = getenv("REMOTE_ADDR");
        } else {
            $ip = UNKNOWN;
        }
        return($ip);
    }


    /**
    * 将某些用户从一个长的用户名字符串中删除，其中用户名字符串必须以“;”隔开，主要在message组件中用到
    *
    * @param string $owners 需要删除的用户
    * @param string $sender 将被删除的用户字符串
    * @author robertyang
    * @access public
    * @return string 被删除后的用户名字符串
    */
    function str_remove_user($owners, $sender)
    {
        $str = ";" . $owners . ";";
        $stringtocut = ";" . $sender . ";";
        $pos = strpos($str, $stringtocut);
        if ($pos === false) {
            //如果需要删除的用户不在其中，则直接返回
            return $owners;
        }
        $l = strlen($stringtocut);	//获得被删字符串总长度
        $len = strlen($str);		//获得删除用户名长度
        $pre = ($pos >= 1) ? substr($str, 1, $pos-1) : "";
        $end = ($len - $l -$pos > 1) ? substr($str, $pos + $l, $len - $l - $pos-1):"";
        $ret = $pre;
        if ((strlen($pre)>0)&&(strlen($end)>0)) {
            $ret .= ";";
        }
        $ret .= $end;
        return $ret;
    }

    /**
    * 将BMP格式的二进制文件恢复成图片
    *
    * @param string $filename 源文件路径
    * @author robertyang
    * @access public
    * @return resource 创建后的图片文件
    */
    function imagecreatefrombmp($filename)
    {
        if (! $f1 = fopen($filename, "rb")) {
            //读取图片源文件
            return false;
        }

        $FILE = unpack("vfile_type/Vfile_size/Vreserved/Vbitmap_offset", fread($f1, 14));	//将源文件的前14位解包至$FILE变量

        if ($FILE['file_type'] != BMP_FILETYPE) {
            //如果不是BMP文件，则直接返回，BMP_FILETYPE即19778为BM的字节存放
            return false;
        }

        $BMP = unpack('Vheader_size/Vwidth/Vheight/vplanes/vbits_per_pixel'.
                                '/Vcompression/Vsize_bitmap/Vhoriz_resolution'.
                                '/Vvert_resolution/Vcolors_used/Vcolors_important', fread($f1, 40));
        //将文件其他信息解包至$BMP变量

        $BMP['colors'] = pow(2, $BMP['bits_per_pixel']);
        if ($BMP['size_bitmap'] == 0) {
            $BMP['size_bitmap'] = $FILE['file_size'] - $FILE['bitmap_offset'];
        }

        $BMP['bytes_per_pixel'] = $BMP['bits_per_pixel'] / 8;	//每byte的像素
        $BMP['bytes_per_pixel2'] = ceil($BMP['bytes_per_pixel']);
        $BMP['decal'] = ($BMP['width']*$BMP['bytes_per_pixel']/4);
        $BMP['decal'] -= floor($BMP['width']*$BMP['bytes_per_pixel']/4);
        $BMP['decal'] = 4 -(4 * $BMP['decal']);
        if ($BMP['decal'] == 4) {
            $BMP['decal'] = 0;
        }

        $PALETTE = array();
        if ($BMP['colors'] < 16777216) {
            $PALETTE = unpack('V' . $BMP['colors'], fread($f1, $BMP['colors']*4));
        }

        $IMG = fread($f1, $BMP['size_bitmap']);
        $VIDE = chr(0);

        $res = imagecreatetruecolor($BMP['width'], $BMP['height']);
        $P = 0;
        $Y = $BMP['height']-1;
        while ($Y >= 0) {
            $X = 0;
            while ($X < $BMP['width']) {
                if ($BMP['bits_per_pixel'] == 24) {
                    $COLOR = unpack("V", substr($IMG, $P, 3) . $VIDE);
                } elseif ($BMP['bits_per_pixel'] == 16) {
                    $COLOR = unpack("n", substr($IMG, $P, 2));
                    $COLOR[1] = $PALETTE[$COLOR[1]+1];
                } elseif ($BMP['bits_per_pixel'] == 8) {
                    $COLOR = unpack("n", $VIDE.substr($IMG, $P, 1));
                    $COLOR[1] = $PALETTE[$COLOR[1]+1];
                } elseif ($BMP['bits_per_pixel'] == 4) {
                    $COLOR = unpack("n", $VIDE . substr($IMG, floor($P), 1));
                    if (($P*2)%2 == 0) {
                        $COLOR[1] = ($COLOR[1] >> 4);
                    } else {
                        $COLOR[1] = ($COLOR[1] & 0x0F);
                    }
                    $COLOR[1] = $PALETTE[$COLOR[1]+1];
                } elseif ($BMP['bits_per_pixel'] == 1) {
                    $COLOR = unpack("n", $VIDE . substr($IMG, floor($P), 1));
                    if (($P*8)%8 == 0) {
                        $COLOR[1] = $COLOR[1] >> 7;
                    } elseif (($P*8)%8 == 1) {
                        $COLOR[1] = ($COLOR[1] & 0x40) >> 6;
                    } elseif (($P*8)%8 == 2) {
                        $COLOR[1] = ($COLOR[1] & 0x20) >> 5;
                    } elseif (($P*8)%8 == 3) {
                        $COLOR[1] = ($COLOR[1] & 0x10) >> 4;
                    } elseif (($P*8)%8 == 4) {
                        $COLOR[1] = ($COLOR[1] & 0x8) >> 3;
                    } elseif (($P*8)%8 == 5) {
                        $COLOR[1] = ($COLOR[1] & 0x4) >> 2;
                    } elseif (($P*8)%8 == 6) {
                        $COLOR[1] = ($COLOR[1] & 0x2) >> 1;
                    } elseif (($P*8)%8 == 7) {
                        $COLOR[1] = ($COLOR[1] & 0x1);
                    }
                    $COLOR[1] = $PALETTE[$COLOR[1] + 1];
                } else {
                    return false;
                }
                imagesetpixel($res, $X, $Y, $COLOR[1]);
                $X ++;
                $P += $BMP['bytes_per_pixel'];
            }
            $Y --;
            $P += $BMP['decal'];
        }
        fclose($f1);
        return $res;
    }

    /**
    * 判断字符串A是否以字符串B开头
    *
    * @param string $str 被比较的字符串A
    * @param string $sub 比较字符串B
    * @author robertyang
    * @access public
    * @return boolean
    */
    function str_begin_with($str, $sub)
    {
        return substr($str, 0, strlen($sub)) === $sub;
    }

    /**
    * 判断字符串A是否以字符串结尾
    *
    * @param string $str 被比较的字符串A
    * @param string $sub 比较字符串B
    * @author robertyang
    * @access public
    * @return boolean
    */
    function str_end_with($str, $sub)
    {
        return substr($str, strlen($str) - strlen($sub)) === $sub;
    }


    function get_utf8_length($string)
    {
        $strlen_en = 0;		//英文字符的长度
        $pa = "/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xef][\x80-\xbf][\x80-\xbf]|\xf0[\x90-\xbf][\x80-\xbf][\x80-\xbf]|[\xf1-\xf7][\x80-\xbf][\x80-\xbf][\x80-\xbf]/";
        //中文字符的正则表达式

        preg_match_all($pa, $string, $t_string);	//在 $string 中搜索所有与 $pa 给出的正则表达式匹配的内容并将结果放到 $t_string中

        //取得中英文字符串长度,字母长度为1,中文字长度记为2
        $strlen_en = count($t_string[0]);
        return $strlen_en;
    }
    /**
    * 截取中文字符。如果遇到中文截断，则多截取一个英文字长度
    *
    * @param string $string 被截取的字符串
    * @param int $sublen 被截取的长度
    * @param int $start 被截取的开始位置
    * @param string $code 编码方式（可选）
    * @author robertyang
    * @access public
    * @return string 截取后的字符串
    */
    function cut_str_ch($string, $sublen = 70, $start = 0, $code = 'UTF-8', $sufix=true)
    {
        if ($code == 'UTF-8') {
            $sub_string = '';	//用作返回字符串
            $strlen_en = 0;		//英文字符的长度
            $pa = "/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xef][\x80-\xbf][\x80-\xbf]|\xf0[\x90-\xbf][\x80-\xbf][\x80-\xbf]|[\xf1-\xf7][\x80-\xbf][\x80-\xbf][\x80-\xbf]/";
            //中文字符的正则表达式

            preg_match_all($pa, $string, $t_string);	//在 $string 中搜索所有与 $pa 给出的正则表达式匹配的内容并将结果放到 $t_string中

            $cut_flag = false;//标记是否截断，修复当$string长度恰好等于$sublen还会添加“...”的bug

            //取得中英文字符串长度,字母长度为1,中文字长度记为2
            foreach ($t_string[0] as $currentChar) {
                if ($cut_flag) {
                    //进入这个if说明同时满足两个条件：
                    //1是字符串还没读完
                    //2是已读取的字符串长度已经达到上限
                    return ($sufix) ? $sub_string.'...' : $sub_string;
                }
                $sub_string .= "$currentChar";
                if (ord($currentChar) < 128) {
                    $strlen_en ++;
                } else {
                    $strlen_en += 2;
                }
                if ($strlen_en > $sublen - 1) {
                    $cut_flag = true;
                }
            }
            return $sub_string;
        } else {
            //非utf8编码
            $start = $start * 2;
            $sublen = $sublen * 2;
            $strlen = strlen($string);
            $tmpstr = '';
            for ($i=0; $i<$strlen; $i++) {
                if ($i >= $start && $i < ($start + $sublen)) {
                    if (ord(substr($string, $i, 1)) > 129) {
                        $tmpstr .= substr($string, $i, 2);
                    } else {
                        $tmpstr .= substr($string, $i, 1);
                    }
                }
                if (ord(substr($string, $i, 1)) > 129) {
                    $i++;
                }
            }
            if (strlen($tmpstr) < $strlen) {
                $tmpstr.= "...";
            }
            return $tmpstr;
        }
    }


    /**
    * 按照一定长度截取字符串，多用于帖子、留言的截取
    *
    * @param string $sourcestr 被截取的字符串
    * @param int $cutlength 被截取的长度
    * @param boolean $add 是否在尾部添加...
    * @author chrishuang
    * deprecated
    * @access public
    * @return string 截取后的字符串
    */
    function text_truncate($sourcestr, $cutlength, $add = true)
    {
        $returnstr = '';	//返回的字符串
        $i = 0;
        $n = 0;
        $str_length = strlen($sourcestr);	//字符串的字节数
        while (($n < $cutlength) and ($i <= $str_length)) {
            $temp_str = substr($sourcestr, $i, 1);
            $ascnum = Ord($temp_str);		//得到字符串中第$i位字符的ascii码
            if ($ascnum >= 224) {
                //如果ASCII位高与224，
                $returnstr = $returnstr . substr($sourcestr, $i, 3); //根据UTF-8编码规范，将3个连续的字符计为单个字符
                $i = $i + 3; //实际Byte计为3
                $n++; //字串长度计1
            } elseif ($ascnum >= 192) {
                //如果ASCII位高与192，
                $returnstr = $returnstr . substr($sourcestr, $i, 2); //根据UTF-8编码规范，将2个连续的字符计为单个字符
                $i = $i + 2; //实际Byte计为2
                $n ++; //字串长度计1
            } elseif ($ascnum >= 65 && $ascnum <= 90) {
                //如果是大写字母
                $returnstr = $returnstr . substr($sourcestr, $i, 1);
                $i = $i + 1; //实际的Byte数仍计1个
                $n = $n + 0.5; //但考虑整体美观，大写字母计成一个高位字符
            } else {
                //其他情况下，包括小写字母和半角标点符号，
                $returnstr = $returnstr . substr($sourcestr, $i, 1);
                $i = $i + 1; //实际的Byte数计1个
                $n = $n + 0.5; //小写字母和半角标点等与半个高位字符宽...
            }
        }
        if (($add)&&($str_length>$i)) {
            $returnstr = $returnstr . "...";//超过长度时在尾处加上省略号
        }
        return $returnstr;
    }

    /**
     * 截取字符串，支持设置截取长度、后缀、起始位置、html标签、中英文字宽、字符编码
     *
     * @param string $str 要截取的字符串
     * @param int $length 字符串长度
     * @param string $suffixStr 截取后带的尾巴
     * @param int $start 截取的起始字符
     * @param string $tags 字符串可能包含的HTML标签
     * @param int $zhfw 中英文字宽参数
     * @param string $charset 字符串编码
     * @author (from internet) Added by Bojam
     * @access public
     */
    function stronger_truncate($str, $length = 100, $suffixStr = "...", $start = 0, $tags = "div|span|p", $zhfw = 0.9, $charset = "utf-8")
    {
        $re ['utf-8'] = "/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|[\xe0-\xef][\x80-\xbf]{2}|[\xf0-\xff][\x80-\xbf]{3}/";
        $re ['gb2312'] = "/[\x01-\x7f]|[\xb0-\xf7][\xa0-\xfe]/";
        $re ['gbk'] = "/[\x01-\x7f]|[\x81-\xfe][\x40-\xfe]/";
        $re ['big5'] = "/[\x01-\x7f]|[\x81-\xfe]([\x40-\x7e]|\xa1-\xfe])/";

        $zhre ['utf-8'] = "/[\xc2-\xdf][\x80-\xbf]|[\xe0-\xef][\x80-\xbf]{2}|[\xf0-\xff][\x80-\xbf]{3}/";
        $zhre ['gb2312'] = "/[\xb0-\xf7][\xa0-\xfe]/";
        $zhre ['gbk'] = "/[\x81-\xfe][\x40-\xfe]/";
        $zhre ['big5'] = "/[\x81-\xfe]([\x40-\x7e]|\xa1-\xfe])/";

        //下面代码还可以应用到关键字加亮、加链接等，可以避免截断HTML标签发生
        //得到标签位置
        $tpos = array();
        preg_match_all("/<(" . $tags . ")([\s\S]*?)>|<\/(" . $tags . ")>/ism", $str, $match);
        $mpos = 0;
        for ($j = 0; $j < count($match [0]); $j ++) {
            $mpos = strpos($str, $match [0] [$j], $mpos);
            $tpos [$mpos] = $match [0] [$j];
            $mpos += strlen($match [0] [$j]);
        }
        ksort($tpos);

        //根据标签位置解析整个字符
        $sarr = array();
        $bpos = 0;
        $epos = 0;
        foreach ($tpos as $k => $v) {
            $temp = substr($str, $bpos, $k - $epos);
            if (! empty($temp)) {
                array_push($sarr, $temp);
            }
            array_push($sarr, $v);
            $bpos = ($k + strlen($v));
            $epos = $k + strlen($v);
        }
        $temp = substr($str, $bpos);
        if (! empty($temp)) {
            array_push($sarr, $temp);
        }

        //忽略标签截取字符串
        $bpos = $start;
        $epos = $length;
        for ($i = 0; $i < count($sarr); $i ++) {
            if (preg_match("/^<([\s\S]*?)>$/i", $sarr [$i])) {
                continue;
            } //忽略标签


            preg_match_all($re [$charset], $sarr [$i], $match);

            for ($j = $bpos; $j < min($epos, count($match [0])); $j ++) {
                if (preg_match($zhre [$charset], $match [0] [$j])) {
                    $epos -= $zhfw;
                } //计算中文字符
            }

            $sarr [$i] = "";
            for ($j = $bpos; $j < min($epos, count($match [0])); $j ++) { //截取字符
                $sarr [$i] .= $match [0] [$j];
            }
            $bpos -= count($match [0]);
            $bpos = max(0, $bpos);
            $epos -= count($match [0]);
            $epos = round($epos);
        }

        //返回结果
        $slice = join("", $sarr); //自己可以加个清除空html标签的东东
        if ($slice != $str) {
            return $slice . $suffixStr;
        }
        return $slice;
    }

    /**
    * 计算某时间相对于当前时间的时间间隔，并以通俗的形式展现，一天为单位
    *
    * @param mixed $time 某时间
    * @author	robertyang
    * deprecated
    * @access	public
    * @return	string	相隔时间的描述
    */
    function calc_relativeTime($time)
    {
        if (!is_int($time)) {
            //如果是字符串，先将字符串转换为时间戳
            $time = strtotime($time);
        }
        if ($time >= mktime(0, 0, 0, date('m'), date('d') + 1, date('Y'))) {
            //某时间为将来的时间
            return '0. In the future';
        } elseif (date('Y-m-d', $time) == date('Y-m-d', time())) {
            //某时间为今天
            return '1. Today';
        } elseif (date('Y-m-d', $time) == date('Y-m-d', strtotime('yesterday'))) {
            //某时间为昨天
            return '2. Yesterday';
        }
        $firstday = mktime(0, 0, 0, date('m'), date('d') - date('w'), date('Y'));
        if ($time > $firstday) {
            //某时间为本周
            return '3. This week';
        }
        if ($time > $firstday- 604800) {
            //上周
            return '4. Last week';
        } elseif ($time >= $firstday - 604800 * 2) {
            //两周前
            return '5. Two weeks ago';
        } elseif ($time >= $firstday - 604800 * 3) {
            //三周前
            return '6. Three weeks ago';
        }

        if ($time > mktime(0, 0, 0, date('m'), 1, date('Y'))) {
            //本月早期
            return '7. Early this month';
        } elseif ($time >= mktime(0, 0, 0, date('m') - 1, 1, date('Y'))) {
            //上月
            return '8. Last month';
        } elseif ($time < mktime(0, 0, 0, date('m') - 1, 1, date('Y'))) {
            //很早以前
            return '9. Even early';
        }
    }

    /**
    * 获得用户请求路径
    *
    * @author	robertyang
    * deprecated
    * @access public
    * @return string 请求路径
    */
    function request_uri()
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            $uri = $_SERVER['REQUEST_URI'];
        } else {
            if (isset($_SERVER['argv'])) {
                $uri = $_SERVER['PHP_SELF'] .'?'. $_SERVER['argv'][0];
            } else {
                $uri = $_SERVER['PHP_SELF'] .'?'. $_SERVER['QUERY_STRING'];
            }
        }
        return $uri;
    }

    /**
    * 将形如workspace/pretty_name的形式转换为workspacePrettyName
    *
    * @param string $id 传入字符串
    * @author robertyang
    * @access public
    * @return string 转换后的字符串
    */
    function clientId($id)
    {
        list($model, $field) = explode('/', $id);
        return $model . Inflector::camelize($field);
    }

    /**
    * 将字符串转换为十六进制数
    *
    * @param string $s 传入字符串
    * @author robertyang
    * @access public
    * @return int 转换后的数字
    */
    function stringToHex($s)
    {
        $hexes = array("0","1","2","3","4","5","6","7","8","9","a","b","c","d","e","f");
        $r = '';
        for ($i=0; $i<strlen($s); $i++) {
            $r .= ($hexes [(ord($s{$i}) >> 4)] . $hexes [(ord($s{$i}) & 0xf)]);
        }
        return $r;
    }

    /**
    * 将十六进制数转换为字符串
    *
    * @param string $h 传入十六进制数
    * @author robertyang
    * @access public
    * @return string 转换后的字符串
    */
    function hexToString($h)
    {
        $r = "";
        for ($i= 0; $i < strlen($h); $i += 2) {
            $r .= chr(base_convert(substr($h, $i, 2), 16, 10));
        }
        return $r;
    }

    /**
    * 将特殊字符转换成html格式（与htmlspecialchars方法同）
    *
    * @param string $string 传入字符串
    * @author robertyang
    * @access public
    * @return string 转换后的字符串
    */
    function escape_chars($string)
    {
        $string = str_replace('&', '&amp;', $string);
        $string = str_replace('<', '&lt;', $string);
        $string = str_replace('>', '&gt;', $string);
        $string = str_replace('\'', '&#39;', $string);
        $string = str_replace('"', '&quot;', $string);
        return $string;
    }


    /**
    * 将文档数组转换后以csv的格式输出
    *
    * @param array $line 传入字符串数组
    * @param string $separator 分割符，csv默认以逗号隔开
    * @author robertyang
    * @access public
    * @return string 转换后的字符串
    */
    function putcsv($line, $separator = ',')
    {
        for ($i=0; $i < count($line); $i++) {
            if (false !== strpos($line[$i], '"')) {
                $line[$i] = ereg_replace('"', '""', $line[$i]);
            }
            if (false !== strpos($line[$i], $separator) || false !== strpos($line[$i], '"')) {
                $line[$i] = '"' . $line[$i] . '"';
            }
        }
        return implode($separator, $line) . "\r\n";
    }

    /**
    * 将字符串编码成URL专用格式，类同于urlencode
    *
    * @param array $url 传入url
    * @author robertyang
    * deprecated
    * @access public
    * @return string	转换后的字符串
    */
    function encodeurl($url)
    {
        $url = explode('/', $url);
        foreach ($url as &$p) {
            $p = urlencode($p);
        }
        return implode('/', $url);
    }

    /**
    * get week's first and last day
    *
    * @param string $year 年份
    * @param string $weekAtYear 周数
    * @author frankychen
    * @return array $days
    */
    function getDays($year, $weekAtYear)
    {
        //找出年份第一天是星期几(1-7)
        $firstDay = date('N ', strtotime("$year-01-01 "));
        //找出第一个星期剩余天数,用于计数.比如2008-01-01号是星期二,则剩余6天到第一个星期天.
        $leftDayInFirstWeek = 8 - $firstDay;
        //星期一的日期(Y-m-d)
        if ($weekAtYear <= 2) {
            $days['monday'] = date("Y-m-d ", strtotime("$year-01-01 ")+($weekAtYear-1)*$leftDayInFirstWeek*24*60*60);
        } else {
            $days['monday'] = date("Y-m-d ", strtotime("$year-01-01 ")+$leftDayInFirstWeek*24*60*60+($weekAtYear-2)*7*24*60*60);
        }
        if ($weekAtYear == 1 && $firstDay != 1) {
            //第一周,且第一天不是星期一
            $days['sunday'] = date("Y-m-0$leftDayInFirstWeek ", strtotime($days['monday']));
        } else {
            $days['sunday'] = date("Y-m-d ", strtotime($days['monday'])+6*24*60*60);
        }
        return $days;
    }

    /**
    * 中文字符音序比较函数，常与uasort方法合用
    *
    * @param string $a 被比较字符串A
    * @param string $b 被比较字符串B
    * @author robertyang
    * @access public
    * @return boolean	比较结果
    */
    function name_cmp($a, $b)
    {
        $replace= array('窦'=>'豆', '噜'=>'鲁');
        foreach ($replace as $key => $value) {
            $a = str_replace($key, $value, $a);
            $b = str_replace($key, $value, $b);
        }
        $a = @iconv('UTF-8', 'GBK', $a);
        $b = @iconv('UTF-8', 'GBK', $b);
        $a = preg_replace('/^(a|an|the) /', '', strtolower($a));
        $b = preg_replace('/^(a|an|the) /', '', strtolower($b));
        return strcasecmp($a, $b);
    }

    /**
    * 获得请求url
    *
    * @author	robertyang
    * @access public
    * @return string	请求url
    */
    function get_referer()
    {
        if (isset($_SERVER['HTTP_REFERER'])) {
            return $_SERVER['HTTP_REFERER'];
        } else {
            return null;
        }
    }

    /**
    * 以浮点数的形式获得毫秒值，同microtime(true)
    *
    * @author	robertyang
    * @access public
    * @return float 当前毫秒值
    */
    function microtime_float()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * 通过迭代id获取迭代名称
     *
     * @param int $iteration_id 迭代id
     * @access public
     * @author robertyang
     * @return string 迭代名称
     */
    function get_iteration_name_by_id($iteration_id)
    {
        if ($iteration_id != 0) {
            $release_interface = get_interface('prong');
            return $release_interface->get_iteration_name($iteration_id);
        } else {
            return '--';
        }
    }

    /**
     * 获取优先级、严重程度等字段的自定义排序
     *
     * @param string $field 字段名称
     * @param string $order 字段顺序
     * @author nemoyin
     * @return string 排序规则，形如“order by priority”
     */
     function get_custom_order_str($field, $sort)
     {
         require_once(PLUGIN_DIRECTORY."bugtrace/config/bugtrace_bootstrap.php");
         if (!in_array($field, array('priority', 'severity'))) {
             return '';
         }
         switch ($field) {
            case 'priority':
                if ('asc' == strtolower($sort)) {
                    $priority_order = array_reverse($GLOBALS['bug_priority_array']);
                } else {
                    $priority_order = $GLOBALS['bug_priority_array'];
                }
                return " field(`priority`,'" . implode("','", $priority_order) . "')";
                break;
            case 'severity':
                if ('asc' == strtolower($sort)) {
                    $severity_order = array_reverse($GLOBALS['bug_severity_array']);
                } else {
                    $severity_order = $GLOBALS['bug_severity_array'];
                }
                return " field(`severity`,'" . implode("','", $severity_order) . "')";
                break;
            default:
                return '';
                break;
        }
     }

    /**
    * 字段排序函数,工作流中用到
    *
    * @param array $value1 比较值1
    * @param array $value2 比较值2
    * @author markguo
    * @access public
    * @return int 比较结果
    */
    function _workitem_appendfield_sort($value1, $value2)
    {
        if (isset($value1['sort']) && isset($value2['sort'])) {
            $key = 'sort';
        } elseif (isset($value1['Sort']) && isset($value2['Sort'])) {
            $key = 'Sort';
        } else {
            return 1;
        }
        if ($value1[$key] > $value2[$key]) {
            return 1;
        } else {
            return -1;
        }
    }

    /**
    * 获得某plugin下的接口实例
    *
    * @param string $plugin 指定plugin
    * @author robertyang
    * @access public
    * @return string 接口实例
    */
    function get_interface($plugin)
    {
        $interface_file = $plugin . '_interface.php';
        $class_name = ucfirst($plugin).'Interface';
        require_once(PLUGIN_DIRECTORY."{$plugin}/{$plugin}_app_model.php");
        require_once(INTERFACE_DIRECTORY. $plugin .'_interface.php');
        $Interface_instance = new $class_name();
        return $Interface_instance;
    }

    /**
    * 判断一个用户是否在人名字段中
    *
    * 例如 判断 joeyue|joeyue;|;joeyue;	 是否在	owner = ‘mic;joeyue;frankychen;shuyafeng;’  中
    *
    * @param string $name name
    * @param string $owner field content   such as 'mic;joeyue;frankychen;shuyafeng;'
    * @author joeyue
    * @return boolean
    */
    function name_exists($name, $owner)
    {
        $name = str_replace(';', '', $name);
        $name = trim($name);

        if (is_array($owner)) {
            $owner = implode(";", $owner);
        }
        $owner = str_replace(' ', '', trim($owner));
        $owner = trim($owner);
        $names = explode(';', $owner);
        if (is_array($names)) {
            return in_array($name, $names);
        }

        return false;
    }


    /**
     * 判断两个数据的字段是否被改变
     *
     * @param array $data_original 改变前的数据
     * @param array $data_new 改变后的数据
     * @param array $fields 候选字段
     * @author robertyang
     * @access public
     * @return array 改变的字段
     */
    function get_change_fields($data_original, $data_new, $fields = array())
    {
        $change_fields = array();
        if (empty($fields)) {
            $fields = array_keys($data_new);
        }
        $old_keys = array_keys($data_original);
        $new_keys = array_keys($data_new);
        foreach ($fields as $item) {
            if (!isset($data_original[$item]) && !in_array($item, $old_keys) || (!isset($data_new[$item]) && !in_array($item, $new_keys))) {
                continue;
            }
            if (is_array($data_new[$item])) {
                $data_new[$item] = implode("|", $data_new[$item]);
            }
            if (is_array($data_original[$item])) {
                $data_original[$item] = implode("|", $data_original[$item]);
            }
            if ($data_original[$item] != $data_new[$item]) {
                $change_fields[] = $item;
            }
        }
        $unset_fields = array('modified');
        foreach ($unset_fields as $unset_field) {
            foreach ($change_fields as $key => $change_field) {
                if ($unset_field == $change_field) {
                    unset($change_fields[$key]);
                }
            }
        }
        return $change_fields;
    }

    /**
     * 获得url内容
     *
     * @param string $url 指定的url
     * @author robertyang
     * @access private
     * @return string 获得的内容
     */
    function __get_url_content($url)
    {
        vendor('curl_helper');
        $curl_helper = new CurlHelper();
        return $curl_helper->get($url);
    }

    /**
    * 按照一定长度截取字符串，多用于帖子、留言的截取
    *
    * @param string $text 被截取的字符串
    * @param int $length 被截取的长度
    * @param string $ending 当字符串被截断时，在尾部添加的字符串，如"..."（可选）
    * @param boolean $exact 是否从空格处断开（可选）
    * @param string $encoding 字符串编码（可选）
    * @author chrishuang
    * @access public
    * @return string 截取后的字符串
    */
    function str_truncate($text, $length, $ending = '...', $exact = true, $encoding='UTF-8')
    {
        if (mb_strlen($text, $encoding) <= $length) {
            return $text;
        } else {
            $truncate = mb_substr($text, 0, $length, $encoding);

            if (!$exact) {
                $spacepos=strrpos($truncate, ' ');
                if (isset($spacepos)) {
                    return mb_substr($truncate, 0, $spacepos, $encoding) . $ending;
                }
            }
            return $truncate . $ending;
        }
    }

    /**
    * 将数组转化为字符串 供SQL查询使用
    *
    * @example	array('16','12','13')
    * @example	转化后为： ('16','12','13')
    *
    * @param array $param 需要被转换的array
    * @author robertyang
    * @access public
    * @return tring 转换后的字符串
    */
    function convert_array_to_sql_string($param = array())
    {
        if (empty($param)) {
            return '';
        }
        $condtion = "";
        $flag = true;
        $condtion .= "(";
        foreach ($param as $value) {
            if ($flag) {
                $condtion .= "'{$value}'";
                $flag =false;
            } else {
                $condtion .= ',';
                $condtion .= "'{$value}'";
            }
        }
        $condtion .= ")";
        if ($condtion == '()') {
            return '';
        } else {
            return $condtion;
        }
    }

    /**
    * 将数组转化为字符串 供SQL查询使用
    *
    * @example	array('16','12','13')
    * @example	转化后为： ('16','12','13')
    *
    * @param array $param 需要被转换的array
    * @author robertyang
    * @access public
    * @return tring 转换后的字符串
    */
    function safe_convert_array_to_sql_string($param = array())
    {
        if (empty($param)) {
            return '';
        }
        $condtion = "";
        $flag = true;
        $condtion .= "(";
        foreach ($param as $value) {
            $value = MyClean::sql($value);
            if ($flag) {
                $condtion .= "'{$value}'";
                $flag =false;
            } else {
                $condtion .= ',';
                $condtion .= "'{$value}'";
            }
        }
        $condtion .= ")";
        if ($condtion == '()') {
            return '';
        } else {
            return $condtion;
        }
    }

    /**
    * 将url中的参数转换为查询条件
    *
    * @param array $fields 包含的字段，即数据库允许接受的字段
    * @param array $params url中的参数，以数组形式传入
    * @author robertyang
    * @access public
    * @return string 转换后的查询字符串
    */
    function condition_build($fields, $params)
    {
        $condition = array();
        foreach ($fields as $field) {
            if (isset($params[$field])) {
                if (isset($params[$field . '_mode'])) {
                    switch ($params[$field . '_mode']) {
                        case '~':
                            $condition[$field] = "like %".rawurldecode($params[$field])."%";
                            break;
                        case '!~':
                            $condition['not'] = array($field=>"like %$params[$field]%");
                            break;
                        case '^':
                            $condition[$field] = "like $params[$field]%";
                            break;
                        case '$':
                            $condition[$field] = "like %$params[$field]";
                            break;
                        case '!':
                            $condition[$field] = "!=$params[$field]";
                            break;
                        case '^!':
                            $persons = explode(";", $params[$field]);
                            $count = 1;
                            foreach ($persons as $key=>$person) {
                                if (!empty($person)) {
                                    if (1 == $count) {
                                        $condition["OR"] = array("concat(';',$field,';')" => "like %;$person;%");
                                    } else {
                                        $condition["OR"] += array("OR" => array("concat(';',$field,';')" => "like %;$person;%"));
                                    }
                                    $count ++;
                                }
                            }
                            break;
                        default:
                            $condition[$field] = $params[$field];
                            break;
                    }
                } else {
                    $condition[$field] = $params[$field];
                }
            }
        }
        return $condition;
    }

    /**
    * 用于得到与现在时间的间隔 （如：2小时前）
    *
    * @param string $timestamp 目标时间
    * @author frankychen
    * @access public
    * @return string 时间间隔描述
    */
    function ago($timestamp)
    {
        $difference = time() - strtotime($timestamp);
        $difference = abs($difference);
        if ($difference < 1) {
            return "刚刚";
        }
        $periods = array("秒", "分钟", "小时", "天", "星期", "月", "年", "十年");
        $lengths = array("60","60","24","7","4.35","12","10");
        for ($j = 0; $difference >= $lengths[$j]; $j++) {
            $difference = $difference / $lengths[$j];
        }
        $difference = floor($difference);
        $text = "$difference $periods[$j]前";
        return $text;
    }


    /**
    * 用于得到时间串
    *
    * @param string $timesstr 目标时间
    * @param string $format 格式
    * @author frankychen
    * @access public
    * @return string 时间串描述
    */
    function d($timesstr, $format='Y-m-d')
    {
        $timestamp = strtotime($timesstr);
        if (!empty($timestamp)) {
            return date($format, $timestamp);
        } else {
            return $timesstr;
        }
    }

    /**
    * 用于得到时间串 2009-12-15 12:00:00 => 2009-12-15
    *
    * @param string $timesstr 目标时间
    * @author frankychen
    * @access public
    * @return string 时间串描述
    */
    function sub_date($timesstr)
    {
        return substr($timesstr, 0, 10);
    }

    /**
     * 计算时间差
     * @param  [type] $date1 [description]
     * @param  [type] $date2 [description]
     * @return [type]		[description]
     */
    function diff_date($date1, $date2)
    {
        if (strtotime($date1) > strtotime($date2)) {
            $ymd = $date2;
            $date2 = $date1;
            $date1 = $ymd;
        }
        list($y1, $m1, $d1) = explode('-', $date1);
        list($y2, $m2, $d2) = explode('-', $date2);

        $y = $m = $d = $_m = 0;
        $math = ($y2 - $y1) * 12 + $m2 - $m1;
        $y = floor($math / 12);
        $m = intval($math % 12);
        $d = (mktime(0, 0, 0, $m2, $d2, $y2) - mktime(0, 0, 0, $m2, $d1, $y2)) / 86400;
        if ($d < 0) {
            $m -= 1;
            $d += date('j', mktime(0, 0, 0, $m2, 0, $y2));
        }
        if ($y > 0 && $m <= 0) {
            $y -= 1;
            $m += 12;
        }
        return array( 'year' => $y, 'month' => $m, 'day' => $d);
    }

    // 获取时间差（天）
    function diff_date_days($date1, $date2)
    {
        $time_diff = strtotime($date2) - strtotime($date1);
        $day = $time_diff/86400;
        return $day;
    }

    /**
    * 用于得到时间串 2009-12-15 12:00:00 => 2009-12-15 12:00
    * 精确到分钟
    *
    * @param string $timesstr 目标时间
    * @author andycwang
    * @access public
    * @return string 时间串描述
    */
    function sub_datetime($timesstr)
    {
        return substr($timesstr, 0, 16);
    }

    /**
    * 返回个人的头像
    *
    * @param string $nick 用户id
    * @author robertyang
    * @access public
    * @return string	<img ... />
    */
    function avatar($nick)
    {
        return UserClient::avatar($nick);
    }

    /**
    * 截取字符串
    *
    * @param string $string 被截取的字符串
    * @param int $sublen 截取长度
    * @param string $code 编码方式（可选）
    * @author	robertyang
    * deprecated
    * @access public
    * @return string 截取后的字符串
    */
    function cut_str($string, $sublen, $code = 'UTF-8', $with_ellipsis = true)
    {
        $strlen = strlen($string);
        $outNum = 0;
        $tmpstr = '';
        if (($strlen)/2< $sublen) {
            return $string;
        }
        for ($i=0; $i< $strlen;) {
            if ($outNum >= $sublen) {
                break;
            }
            if (ord(substr($string, $i, 1))>129) {
                $tmpstr.= substr($string, $i, 3);
                $i += 3;
            } else {
                if (ord(substr($string, $i+1, 1)) > 129) {
                    $tmpstr.= substr($string, $i, 1);
                    $i += 1;
                } else {
                    $tmpstr.= substr($string, $i, 2);
                    $i += 2;
                }
            }
            $outNum++;
        }
        if (strlen($tmpstr)< $strlen && $with_ellipsis) {
            $tmpstr.= "...";
        }
        return $tmpstr;
    }

    /**
    * 用于将params中的url数组组装url字符串
    *
    * @param array $array_params_url url数组
    * @param array $parent_name url中的key值
    * @param array $except 需要跳过的key
    * @author markguo
    * @return string 组装后的url
    */
    function _convert_urlArray_to_urlString($array_params_url, $parent_name = '', $except = array())
    {
        if (!isset($array_params_url) || empty($array_params_url)) {
            return false;
        }
        $url = '';
        foreach ($array_params_url as $key => $params) {
            if (in_array($key, $except)) {
                continue;
            }
            if ('url' === $key) {
                $url .= $params;
                if (count($array_params_url)>1) {
                    $url .= "?";
                }
            } else {
                $key = urlencode($key);
                if (is_array($params)) {
                    $next_parent_name = (!empty($parent_name))?$parent_name . '[' . $key . ']' : $key;
                    $url .= _convert_urlArray_to_urlString($params, $next_parent_name);
                } else {
                    $params = urlencode($params);
                    $url .= (!empty($parent_name)) ? $parent_name . '[' . $key . ']=' . $params . '&' : "$key=$params&";
                }
            }
        }
        return $url;
    }

    function is_utf8($word)
    {
        if (preg_match("/^([".chr(228)."-".chr(233)."]{1}[".chr(128)."-".chr(191)."]{1}[".chr(128)."-".chr(191)."]{1}){1}/", $word) == true || preg_match("/([".chr(228)."-".chr(233)."]{1}[".chr(128)."-".chr(191)."]{1}[".chr(128)."-".chr(191)."]{1}){1}$/", $word) == true || preg_match("/([".chr(228)."-".chr(233)."]{1}[".chr(128)."-".chr(191)."]{1}[".chr(128)."-".chr(191)."]{1}){2,}/", $word) == true) {
            return true;
        } else {
            return false;
        }
    } // function is_utf8

    /**
    * 查看页面的上一个、下一个
    *
    * @param int $current_id 当前对象id
    * @param array $all_ids 结果id序列
    * @author emilyzhang
    * @access public
    * @return array 上一个id和下一个id
    */
    function find_next_previous($current_id, $all_ids)
    {
        $all_id_array = $all_ids;
        $previous_next = array();
        if (!empty($all_id_array)) {
            foreach ($all_id_array as $key => $value) {
                if ($value['id'] == $current_id) {
                    if ($key == 0 && $key<=count($all_id_array)-2) {
                        //列表中的第一个对象
                        $previous_next['previous'] = "";
                        $previous_next['next'] = $all_id_array[$key+1];
                    } elseif (($key == count($all_id_array)-1)&&count($all_id_array) != 1) {
                        //列表中的最后一个对象
                        $previous_next['previous'] = $all_id_array[$key-1];
                        $previous_next['next'] = "";
                    } elseif (count($all_id_array) == 1) {
                        //列表中的只有一个对象
                        $previous_next['previous'] = "";
                        $previous_next['next'] = "";
                    } else {
                        $previous_next['previous'] = $all_id_array[$key-1];
                        $previous_next['next'] = $all_id_array[$key+1];
                    }
                    break;
                }
            }
        }
        unset($all_id_array);
        return $previous_next;
    }

    /**
    * 去掉URL地址中的某个参数，同时返回要为URL增加新参数的方法
    *
    * @param string $arg URL 地址中的通过？方式传递的某个参数，同时删除参数后的新地址将通过URL返回
    * @param string &$URL 需要删除的url
    * @access public
    * @author robertyang
    * @return int 0|1|  0代表新加参数时需用？  1代表新加参数时用&
    */
    function delete_arg_from_url_old($arg, &$URL)
    {
        if (empty($URL) || !strpos($URL, '?') || !strpos($URL, '?')) {
            return 0;
        }
        $URL	   = str_replace('?', '&', $URL);
        $url_aray  = explode('&', $URL);
        $real_url  = $url_aray[0];
        $args	  = array();

        if (count($url_aray) > 1) {
            $args  = array_slice($url_aray, 1);
        }
        if (empty($args) || !is_array($args)) {
            return 0;
        }

        foreach ($args as $key=>$tmp_arg) {
            $matched = preg_match("/{$arg}=/i", $tmp_arg);
            if (!empty($matched) || empty($tmp_arg)) {
                unset($args[$key]);
            }
        }

        $query_string = implode('&', $args);
        $URL = empty($query_string) ? $real_url : $real_url.'?'.$query_string;
        return empty($query_string) ? 0 : 1;
    }

    /**
    * 去掉URL地址中的某个参数，同时返回要为URL增加新参数的方法
    *
    * @param string $arg URL 地址中的通过？方式传递的某个参数，同时删除参数后的新地址将通过URL返回
    * @param string &$URL 需要删除的url
    * @access public
    * @author robertyang
    * @return int 0|1|  0代表新加参数时需用？  1代表新加参数时用&
    */
    function delete_arg_from_url($arg, &$URL)
    {
        if (!strpos($URL, '?') && !strpos($URL, '&')) {
            $add = 0;
        } else {
            $URL = str_replace('?', '&', $URL);
            $tmp_url = explode('?', $URL);
            if (!empty($tmp_url[1])) {
                $real_url = (!empty($tmp_url[0]))?$tmp_url[0]:$URL;
                $params = $tmp_url[1];
                $args = explode('&', $params);
            } else {
                $args = explode('&', $URL);
                $real_url = (!empty($args[0]))?$args[0]:$URL;
                if (!empty($args) && is_array($args)) {
                    if (count($args) > 1) {
                        $args = array_slice($args, 1, count($args) - 1);
                    }
                }
            }
            if (empty($args) || !is_array($args)) {
                return 0;
            }
            foreach ($args as &$tmp_arg) {
                $matched = preg_match("/$arg=/i", $tmp_arg);
                if (!empty($matched)) {
                    $tmp_arg = '';
                    // break;
                }
            }
            $now_params = "";
            $flag = true;
            foreach ($args as $value) {
                if (!empty($value)) {
                    if ($flag) {
                        $now_params .= $value;
                        $flag = false;
                    } else {
                        $now_params .= '&'.$value;
                    }
                }
            }
            if (!empty($now_params)) {
                $URL = $real_url .'?'.$now_params;
                $add = 1;
            } else {
                $URL = $real_url ;
                $add = 0;
            }
        }
        return $add;
    }

    /**
    * 提供方法支持菜单多级的情况,和面包屑
    *
    * 得到当前位置位置在的菜单层级数量， 例如 某个链接为2级菜单 那么层级数量就为2
    *
    * @param string $current_location current_location
    * @param array $locations locations
    * @author joeyue
    * @return int menu级别
    */
    function get_current_memu_level($current_location, $locations)
    {
        $level = 1;
        while (!empty($locations[$current_location]['parent'])) {
            $current_location = $locations[$current_location]['parent'];
            $level++;
        }
        return $level;
    }

    /**
    * 提供方法支持菜单多级的情况,和面包屑
    * 以现在位置为标准得到，某一层级上的menu信息
    *
    * @param int $level level
    * @param string $current_location current_location
    * @param array $locations locations
    * @author joeyue
    * @return array 对应的menu信息
    */
    function get_memu_by_level($level, $current_location, $locations)
    {
        $total_level = get_current_memu_level($current_location, $locations);
        $temp_level = 1;
        while (!empty($locations[$current_location])) {
            if (($total_level-$temp_level) == $level) {
                return array($current_location=>$locations[$current_location]);
            }
            $current_location = $locations[$current_location]['parent'];
            $temp_level++;
        }
        return array();
    }

    /**
    * 清除字符串中的html、js、css标签
    *
    * @param string $str 需要被清理的字符串
    * @author robertyang
    * @return string 清理后的字符串
    */
    function clear_title($str)
    {
        $str = preg_replace("@<script(.*?)</script>@is", "", $str);
        $str = preg_replace("@<iframe(.*?)</iframe>@is", "", $str);
        $str = preg_replace("@<style(.*?)</style>@is", "", $str);
        $str = preg_replace("@<(.*?)>@is", "", $str);
        return $str;
    }

    /**
     * 去掉详细内容中的html标签
     *
     * @param string $str 清理前的内容
     * @author robertyang
     * @access public
     * @return string 清理后的内容
     */
    function clean_html($str)
    {
        $str = strtolower($str);
        $str = preg_replace("@<script(.*?)</script>@is", '', $str);
        $str = preg_replace("@<iframe(.*?)</iframe>@is", '', $str);
        $str = preg_replace("@<style(.*?)</style>@is", '', $str);
        $str = preg_replace("@<(.*?)>@is", '', $str);
        $str = str_replace("&nbsp;", '', $str);
        return $str;
    }

    function clean_img($str)
    {
        $str = preg_replace("/<img(.*?)\/>/", '[！图片]', $str);
        return $str;
    }


    /**
     * 清楚行内样式，保留换行
     * @param string $str 清理前的内容
     * @return string 清理后的内容
     */
    function clean_style($str)
    {
        $str = strip_tags($str, '<br><br/><div><p><ul><ol><li><dl><dd>');
        $str = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $str);
        return $str;
    }


    /**
     * 将url中的参数放到input hidden中去,自动生成<input type='hidden'>
     *
     * @param array $array_params_url 一般来说是$this->params['url']
     * @param string $parent_name 是上一层的名字,一般调用者填''
     * @param array $except 不希望被保留下来的字体段,比如:array('Filter','page');
     * @author kuncai
     * @return boolean 是否成功
     */
    function format_url($array_params_url, $parent_name = '', $except = array())
    {
        if (!isset($array_params_url) || empty($array_params_url)) {
            return false;
        }
        foreach ($array_params_url as $key=>$params) {
            if ($key == 'url' || in_array($key, $except)) {
                continue;
            } else {
                if (is_array($params)) {
                    $next_parent_name = (!empty($parent_name)) ? $parent_name . '[' . $key . ']' : $key;
                    format_url($params, $next_parent_name, $except);
                } else {
                    $name = (!empty($parent_name))?$parent_name."[".$key."]":"$key";
                    $name = MyClean::html($name);
                    $params = MyClean::html($params);
                    echo "<input type='hidden' name='{$name}' value='{$params}'>";
                }
            }
        }
    }

    /**
     * 对数组的值进行字符串替换操作
     *
     * @param mixed $search 被替换的字符串
     * @param string $replace 替换字符串
     * @param mixed $obj 被替换的对象
     * @author kuncai
     * @return mixed 替换后的数组
     */
    function str_replace_array($search, $replace, $obj)
    {
        $res = array();
        if (empty($obj)) {
            return $res;
        }
        foreach ($obj as $key=>$value) {
            $value_res = $value;
            if (is_array($value)) {
                $value_res = str_replace_array($search, $replace, $value);
            } else {
                $value_res = str_replace($search, $replace, $value);
            }
            $key_res = str_replace($search, $replace, $key);
            $res[$key_res]=$value_res;
        }
        return $res;
    }
    /**
     * 把特殊字符转义为html字符
     *
     * @param string $src 需要转义的字符串
     * @author nemoyin
     * @return string 转换后的字符串结果
     */
    function decode_special_byte_to_html($src)
    {
        $dst = $src ;
        $dst = str_replace(CHR(10), "<br>", $dst) ;
        return $dst ;
    }

    /**
     * 把人名中的中文,(,),等字符过滤掉
     *
     * @param string $src 被过滤的人名字符串
     * @author nemoyin
     * @return string 转换后的字符串结果
     */
    function filter_c_name_in_user_name($src)
    {
        $pattern = '/[a-zA-Z;]+/';
        $replace = '';
        $string = $src;
        $exclude = preg_replace($pattern, $replace, $string);
        return str_replace($exclude, '', $string);
    }

    /**
     * 用文件方式调试
     *
     * 使用文件方式进行调试，将调试的输出输出到 APP.'tmp'.DS.'test.txt'文件中,该文件默认将自动创建
     * 因此APP.'tmp'.DS.'test.txt'文件必须存在 如：/home/joeyue/public_html/tapd3/app/tmp/test.txt
     *
     * @param string $var 调试输出的内容
     * @author  joeyue
     */
    function fdebug($var, $filename = 'test.txt')
    {
        if (!file_exists(APP.'tmp'.DS.$filename)) {
            $file = fopen(APP.'tmp'.DS.$filename, 'x+');
            if (false !== $file) {
                fclose($file);
            }
        }
        chmod(APP.'tmp'.DS.$filename, 0777);
        $file = fopen(APP.'tmp'.DS.$filename, 'ab');
        if (is_array($var)) {
            //这里可以继续完善，输出数组来，而不是json后的字符串
            fwrite($file, json_encode($var));
        } else {
            fwrite($file, $var);
        }
        fwrite($file, "\n");
        fclose($file);
    }

    /**
     * 在相关内容更新后，清除BUG在生成统计报表时的相关的字段信息的缓存
     *
     * @param int $workspace_id workspace的id
     * @access public
     * @author joeyue
     */
    function delete_bug_stat_cache($workspace_id)
    {
        $cache_key = 'system_data_framework_bug_stat_' . $workspace_id;
        MyCache::delete($cache_key);
        delete_whole_user_default_view_cache();
        return;
    }

    /**
     * 清除指定页面的用户默认视图缓存
     *
     * @param string $user 用户名
     * @param string $location 视图归属
     * @param int $workspace_id 工作区ID
     * @author markguo
     */
    function delete_user_default_view_cache($user, $location, $workspace_id)
    {
        $key = md5($user . '::' . $location . '::' . $workspace_id);
        $cache_user_default_views = MyCache::get('user_default_view');
        if (!empty($cache_user_default_views)) {
            if (array_key_exists($key, $cache_user_default_views)) {
                unset($cache_user_default_views[$key]);
            }
        }
        MyCache::add('user_default_view', $cache_user_default_views, '+30 day');
        return;
    }

    /**
     * 清除所有用户默认视图缓存
     *
     * @author markguo
     */
    function delete_whole_user_default_view_cache()
    {
        $cache_key = 'user_default_view';
        MyCache::delete($cache_key);
        return;
    }

    /**
     * 基础对象的字段配置更新后，清除缓存
     *
     * @param string $entry_type 对象类型
     * @param int $workspace_id 项目id
     * @access public
     * @author nemoyin
     */
    function delete_item_cache($entry_type, $workspace_id)
    {
        $cache_key = 'system_data_framework_' . $entry_type ."_". $workspace_id;
        if ('bug' == $entry_type) {
            MyCache::delete($cache_key . '_zh_CN_');
            MyCache::delete($cache_key . '_zh_CN_1');
            MyCache::delete($cache_key . '_en_1');
            MyCache::delete($cache_key . '_en_');
            MyCache::delete($cache_key . '_zh_CN_hebe_');
            MyCache::delete($cache_key . '_zh_CN_hebe_1');
            MyCache::delete($cache_key . '_en_hebe_1');
            MyCache::delete($cache_key . '_en_hebe_');
        } elseif ('story' == $entry_type) {
            MyCache::delete($cache_key . '_zh_CN');
            MyCache::delete($cache_key . '_en');
        } else {
            MyCache::delete($cache_key);
        }
        
        delete_whole_user_default_view_cache();
        if ('bug' === $entry_type) {
            delete_bug_stat_cache($workspace_id);
        }
        if (tapd_in_array($entry_type, array('iteration'))) {
            ClassFactory::import('CacheProvider');
            CacheProvider::clear_obj_field_info_cache($entry_type, $workspace_id);
        }
        return;
    }

    /**
     * bug工作流中appendfield中排序回调函数
     *
     * @param array $value1 排序比较值1
     * @param array $value2 排序比较值2
     * @author nemoyin
     * @access public
     */
    function bug_appendfield_sort($value1, $value2)
    {
        if (isset($value1['sort']) && isset($value2['sort'])) {
            $key = 'sort';
        } elseif (isset($value1['Sort']) && isset($value2['Sort'])) {
            $key = 'Sort';
        } else {
            return 1;
        }
        if ($value1[$key] > $value2[$key]) {
            return 1;
        } else {
            return -1;
        }
    }

    /**
     * 检测是否日期时间格式
     *
     * @param string $string 日期字符串
     * @author joeyue
     * @return boolean
    */
    function is_date_time_format($string, $formats = array())
    {
        $unixtime = strtotime($string);
        if (!$unixtime) {
            return false;
        }
        if (empty($formats)) {
            return true;
        } else {
            foreach ($formats as $format) {
                if (date($format, $unixtime) == $string) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 删除dir
     *
     * @param string $dir dir路径
     * @access public
     * @author robertyang
     * @return boolean 是否成功
     */
    function deldir($dir)
    {
        $dh = opendir($dir);
        while ($file = readdir($dh)) {
            if ($file != '.' && $file != '..') {
                $fullpath = $dir . '/' . $file;
                if (!is_dir($fullpath)) {
                    unlink($fullpath);
                } else {
                    deldir($fullpath);
                }
            }
        }
        closedir($dh);
        if (rmdir($dir)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 为过长而又没有空格的含英文字符串添加空格
     *
     * @param string $string 需要修改的文字
     * @param int $length 每行文字长度
     * @param string $sub 替换的字符，默认为空格
     * @author robertyang
     * @return $string 结果字符串
     */
    function str_add_blank($string, $length, $sub = ' ')
    {
    }

    /**
     * echo something with cleanValue
     *
     * @param string $str 需要清理的字符串
     * @author kuncai
     * @access public
     */
    function echo_clean($str)
    {
        $str = MyClean::cleanValue($str);
        echo $str;
    }

    /**
     * 判断富文本框内容是否为空，即除掉一些不必要的标签
     *
     * @param string $description 详细描述
     * @access public
     * @author robertyang
     * @return boolean 是否为空
     */
    function is_rich_empty($description)
    {
        return empty($description) || ($description == "<br>") || ($description == "<P>&nbsp;</P>");
    }

    /**
     * 预处理传入字符串或数组：去除重复；去掉多余的分隔符，主要用于页面显示前的处理
     *
     * @param mix $items 待处理实体-数组或字符串
     * @param string $separator 分隔符-针对传入实体字符串的情况
     * @param boolean $remove_reduplicated_item 是否去除重复的实体
     * @return mix $items_array_unique/$ret_str/false
     * @author markguo
     * @access public
     */
    function pretreat_items_list($items, $separator = ';', $remove_reduplicated_item = true)
    {
        if (is_array($items)) {
            $items_array_unique = array_unique($items); //保留传入数组的键值
            return $items_array_unique;
        } else {
            $items = trim($items, $separator);
            $items .= $separator;
            $ret_str = $items;
            if ($remove_reduplicated_item) {
                $itmes_array = array_filter(explode($separator, $items));
                if (is_array($itmes_array)) {
                    $items_array_unique = array_unique($itmes_array);
                }
                if (!empty($items_array_unique)) {
                    $ret_str = implode($separator, $items_array_unique);
                }
            }
            return $ret_str;
        }
    }
    /**
     * 获取需求无法填写任务时的提示
     *
     * @param int $code 提示代码
     * @author robertyang
     * @access private
     * @return string 提示
     */
     function get_timesheet_hint($code)
     {
         switch ($code) {
            case TIMESHEET_ENTRANCE_DISABLED_STORY_WITH_TASKS:
                return t('Est. effort is aggregated by splitted tasks Est. efforts.');
            case TIMESHEET_ENTRANCE_DISABLED_IS_NOT_LEAF_STORY:
                return t('Est. effort is aggregated by splitted stories Est. efforts.');
            case TIMESHEET_ENTRANCE_DISABLED_MEASUREMENT_DISABLED:
                return t('As the measurement is not enabled');
            case TIMESHEET_ENTRANCE_DISABLED_ACTUAL_EFFORTS_EXIST:
                return t('Est. effort can not be modified since actual efforts already exist.');
            default:
                return '';
        }
     }

/**
 * 获取bug自定义字段的定义全数组
 *
 * @access public
 * @author robertyang
 * @return array 自定义字段数组
 */
function get_bug_custom_fields()
{
    static $bug_custom_fields;
    if (!empty($bug_custom_fields)) {
        return $bug_custom_fields;
    }
    $bug_custom_fields = array('custom_field_one', 'custom_field_two', 'custom_field_three', 'custom_field_four', 'custom_field_five');
    for ($i = 6; $i <= BUG_CUSTOMFIELD_COUNT; $i ++) {
        $bug_custom_fields[] = 'custom_field_' . $i;
    }

    return $bug_custom_fields;
}

/**
 * 获取需求自定义字段的定义全数组
 *
 * @access public
 * @author robertyang
 * @return array 自定义字段数组
 */
function get_story_custom_fields()
{
    static $story_custom_fields;
    if (!empty($story_custom_fields)) {
        return $story_custom_fields;
    }
    $story_custom_fields = array('custom_field_one', 'custom_field_two', 'custom_field_three', 'custom_field_four', 'custom_field_five', 'custom_field_six', 'custom_field_seven', 'custom_field_eight');

    for ($i = 9; $i <= STORY_CUSTOMFIELD_COUNT; $i ++) {
        $story_custom_fields[] = 'custom_field_' . $i;
    }
    return $story_custom_fields;
}

/**
 * 获取需求和任务的自定义字段的定义全数组
 *
 * @access public
 * @author nemoyin
 * @return array 自定义字段数组
 */
function get_workitem_custom_fields()
{
    $ret = array('custom_field_one', 'custom_field_two', 'custom_field_three', 'custom_field_four', 'custom_field_five', 'custom_field_six', 'custom_field_seven', 'custom_field_eight');
    return $ret;
}

/**
 * 从数组A中删除数组B
 *
 * @param array $a 被删除的数组a
 * @param array $b 删除数组b
 * @author robertyang
 * @access public
 * @return array 删除后的数组
 */
 function array_value_unset($a, $b)
 {
     if (!is_array($a) || !is_array($b)) {
         return $a;
     }
     foreach ($b as $key => $value) {
         foreach ($a as $k => $v) {
             if ($v === $value) {
                 unset($a[$k]);
             }
         }
     }
     return $a;
 }

 /**
  * 取出数组二级中的某个字段的值
  *
  * @author kuncai
  */
 function array_field_values($array, $model_name, $field)
 {
     $rs = array();
     if (!empty($array)) {
         foreach ($array as $key => $value) {
             if (isset($value[$model_name][$field])) {
                 $rs[] = $value[$model_name][$field];
             } elseif (isset($value[$field])) {
                 $rs[] = $value[$field];
             } else {
                 $rs[] = null;
             }
         }
     }
     return $rs;
 }

//已经移到elements/pager_ajax.thtml上 by kerwinzhang 2012-11-17

// /**
//  * 异步吐出翻页空间
//  *
//  * @package Config
//  * @author robertyang
//  */
// class AsyPager{
// 	/**
// 	 * 异步吐出翻页控件
// 	 *
// 	 * @param array $pager_Init_Params 突出时所带的参数
// 	 * @author robertyang
// 	 * @access public
// 	 * @return void
// 	 */
// 	function outputPager($pager_Init_Params) {
// 		if (!empty($pager_Init_Params['url'])) {
// 			$params = array('mode' => 'Sliding', 'perPage' => 10, '_delta' => 8, 'totalItems' => '', 'httpMethod' => 'GET', 'currentPage' => 1, 'linkClass' => 'pager', 'altFirst' => 'First page', 'altPrev ' => 'Previous page', 'altNext' => 'Next page', 'altLast' => 'Last page', 'separator' => '', 'spacesBeforeSeparator' => 1, 'spacesAfterSeparator' => 1, 'useSessions' => false, 'firstPagePre' => '', 'firstPagePost' => '', 'firstPageText' => t('<<', '/tapd'), 'lastPagePre' => '', 'lastPagePost' => '', 'lastPageText' => t('>>', '/tapd'), 'prevImg' => t('<', '/tapd'), 'nextImg' => t('>', '/tapd'), 'altPage' => t('Page'), 'clearIfVoid' => true, 'append' => false, 'path' => '', //'fileName' => $this->Controller->base . DS . $url . DS . (($pass)?$pass.DS:'') . '?'.$q.'page=%d',
// 			'fileName' => $pager_Init_Params['url'] . 'page=%d', 'urlVar' => '');

// 			unset($pager_Init_Params['url']);

// 			vendor('Pager/Pager');

// 			// Merge with user config
// 			$params = array_merge($params, $pager_Init_Params);

// 			// sanitize requested page number
// 			if (!in_array($params['currentPage'], range(1, ceil($params['totalItems'] / $params['perPage'])))) {
// 				$params['currentPage'] = 1;
// 			}
// 			$Pager = & Pager::factory($params);

// 			$pageLinks = $Pager->getLinks();

// 			$page_links_not_empty = isset($pageLinks['all']) && !empty($pageLinks['all']);
// 			echo '<div class="tapd-pagination">';
// 			if($page_links_not_empty) {
// 				echo $pageLinks['all'];
// 			}
// 			echo '</div>';
// 		}
// 	}
// }
// eof 已经移到elements/pager_ajax.thtml上 by kerwinzhang 2012-11-17

    /**
    * 判断下拉框是否需要增强：只有工作流会用
    *
    * @param string $field_name 字段名
    *
    */
    function is_dk_select($field_name)
    {
        return in_array(
            $field_name,
            array('iteration_id', 'version_report', 'version_test', 'feature','baseline_close',
                            'version_fix', 'version_closed', 'module', 'version_close', 'baseline_test', 'baseline_find', 'baseline_join')
        );
    }

    /**
     * 获取中文的周X
     *
     * @param string $w_day 字的周x
     * @author robertyang
     * @access public
     * @return string 中文的周x
     */
     function get_chinese_w_day($w_day)
     {
         $w_array = array('星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六');
         return array_key_exists($w_day, $w_array) ? $w_array[$w_day] : $w_day;
     }

     function get_parent_ids()
     {
         require_once('tapd_app_config.php');
         return $GLOBALS['workspaces_enable_app'];
     }

     /**
      * 获取版本的默认类型，等以后引入了数据库设置后再重构
      *
      * @param int $workspace_id workspace id
      * @author kuncai
      * @access public
      * @return array 版本类型数据
      */
     function get_default_version_types($workspace_id)
     {
         $types = array('','Normal version', 'Temp version');
         return $types;
     }

     /**
      *
      * 获取版本的关闭状态数组
      * @return array 状态数组，其中key为complete字段，value为对应的英文翻译
      */
     function get_version_complete_status()
     {
         return array(
            0 => 'Unclosed',
            1 => 'Closed'
        );
     }

     /**
     * 获取需求的优先级数组，包括数字与文字的映射关系
     *
     * @author robertyang
     * @access public
     * @return array 优先级数组
     */
    function getStoryPriorityArray()
    {
        $prioritys = array(4 => 'High', 3 => 'Middle', 2 => 'Low', 1 => 'Nice To Have');
        return $prioritys;
    }

    /**
     * 获取任务优先级数组，以及数字与文字的对应关系
     *
     * @author robertyang
     * @access public
     * @return int 优先级文字
     */
    function getTaskPriorityArray()
    {
        $prioritys = array(4=> 'High', 3 => 'Middle', 2 => 'Low', 1 => 'Nice To Have');
        return $prioritys;
    }

    /**
     * 得到bug优先级数组，和其对应的t函数后的结果
     */
    function getBugPriorityArray()
    {
        return array('--' => '--', 'urgent' => t('urgent'), 'high' => t('high'), 'medium' => t('medium'), 'low' => t('low'), 'insignificant' => t('insignificant'));
    }

    function getSeverityMap()
    {
        return array('advice' => '建议', 'prompt' => '提示', 'normal' => '一般', 'serious' => '严重', 'fatal' => '致命', 'empty' => '--', '' => '--');
    }
    
    /**
     * 从全局加载的js、css文件中找出重复加载的静态资源文件
     *
     * @param array $url_array 被检测的静态资源名数组
     * @param string $sub_path 文件相对于站点根节点的目录
     * @author robertyang
     * @access public
     * @return array 重复加载的资源
     */
    function check_for_duplicate_sources($url_array, $sub_path = JS_URL)
    {
        $ret = array();
        if (substr($sub_path, strlen($sub_path) - 1, 1) == DS) {
            $file_type = substr($sub_path, 0, strlen($sub_path) - 1);
        } else {
            $file_type = $sub_path;
        }

        $url_tmp = $url_array;
        foreach ($url_tmp as &$url) {
            if (strpos($url, '://')) {
                //去掉子全路径
                $url = str_replace(BASE_PATH . $sub_path, '', $url);
            }
            if (strpos($url, '.' . $file_type) !== false) {
                //去掉文件后缀
                $url = substr($url, 0, strpos($url, '.' . $file_type));
            }
        }
        $value_count = array_count_values($url_tmp);
        foreach ($value_count as $value => $count) {
            if ($count > 1) {
                foreach ($url_array as $url) {
                    if (strpos($url . '.' . $file_type, $value . '.' . $file_type) !== false) {
                        $ret[$value . '.' . $file_type][] = $url;
                    }
                }
            }
        }
        return $ret;
    }

    function parse_xml_data_to_array($xml_data)
    {
        if (function_exists('vendor')) {
            vendor("XML_Serializer/Unserializer");
        } else {
            $file_path = dirname(__FILE__);
            require_once(APP_DIR ."/vendors/XML_Serializer/Unserializer.php");
        }
        $parser = new XML_Unserializer();
        $parser->unserialize($xml_data);
        $data = $parser->getUnserializedData();
        return $data;
    }

    /**
     * 更新support单的状态
     *
     * @param int $id
     * @param int $fid
     * @param int $status
     */
    function update_support_status_callback($id, $fid, $rtx='', $status = 0)
    {
        if (!empty($id) && !empty($fid)) {
            $api_url = SUPPORT_API_PATH . "optapdCallback_api?tid=$id&fid=$fid&type=$status&fmt=1&rtx=$rtx";
            $ret = get_curl_content($api_url);
            if ($ret !== false) {
                $ret = json_decode($ret, true);
                return (isset($ret['retcode'])&&(0 == $ret['retcode'])) ? true : false;
            } else {
                //错误log
                return false;
            }
        }
    }

    /**
     * 调用support接口获取已转单的帖子列表
     *
     * @param array $fid
     * @param int $count
     * @author nemoyin
     */
    function get_support_list($fid, $count = 100, $support_flag = 20)
    {
        if (!empty($fid)) {
            $api_url = SUPPORT_API_PATH . "postlist_api?fid=$fid&order=$support_flag&fmt=1";
            //$ret = get_curl_content($api_url);
            vendor('curl_helper');
            $curl_helper = new CurlHelper();
            $curl_helper->set_time_out(60);
            $ret = $curl_helper->get($api_url);
            if ($ret !== false) {
                $ret = json_decode($ret, true);
                return !empty($ret['list']) ? $ret['list'] : array();
            } else {
                //错误log
            }
        }
    }

    /**
     * 调用support平台的接口获取帖子数据
     *
     * @param int $id
     * @param int $forum_id
     * @author nemoyin
     */
    function get_support_sheet($id, $forum_id)
    {
        $api_url = SUPPORT_API_PATH . "content_api?tid=$id&fid=$forum_id";
        $xml_data = get_curl_content($api_url);
        if ($xml_data !== false) {
            $sheet = parse_xml_data_to_array($xml_data);
            $ret = array('name'=>$sheet['post_result']['content']['post_title'],
                         'description'=>$sheet['post_result']['content']['post_text'],
                         'client_qq'=>$sheet['post_result']['content']['post_qq'],
                         'client_info'=>$sheet['post_result']['content']['client_info'],
                         'post_time'=>$sheet['post_result']['content']['post_time'],
                         'modify_time'=>$sheet['post_result']['content']['modify_time'],
                         'client_ip'=>$sheet['post_result']['content']['client_ip'],
                         'best_reply_id'=>$sheet['post_result']['content']['best_reply_id'],
                         'post_flag'=>$sheet['post_result']['content']['post_flag'],
                         'reply_num'=>$sheet['post_result']['content']['reply_num'],
                         'self_view_num'=>$sheet['post_result']['content']['self_view_num'],
                         );
            return $ret;
        } else {
            return array();
        }
    }

    /**
     * 调用curl取数据
     *
     * @param string $url 请求的url
     * @author nemoyin
     */
    function get_curl_content($url)
    {
        if (strlen($url) > 800) {
            $p = strpos($url, '?');
            if ($p !== false) {
                $params = substr($url, $p + 1, strlen($url) - $p - 1);
                $url = substr($url, 0, $p);
            }
        }
        $ch = curl_init($url);
        if (isset($params)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_NOBODY, 0);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $contents = curl_exec($ch);
        curl_close($ch);
        return $contents;
    }

    /**
     * 将一段html文本转化为txt文本
     *
     * @param string $html 传入的html文本
     * @author joeyue
     * @return string $text
    */
    function html2text($html, $allowable_tags = null)
    {
        $html = str_replace('&nbsp;', ' ', $html); //&nbsp替换为空格
        //在<P><p> </P></p><br><br/><BR><BR/>后面加入换行符号
        $html = str_replace('<P>', "<P>\n  ", $html);
        $html = str_replace('</P>', "</P>\n  ", $html);
        $html = str_replace('<p>', "<p>\n  ", $html);
        $html = str_replace('</p>', "</p>\n  ", $html);
        $html = str_replace('<li>', "<li>\n  ", $html);
        $html = str_replace('</li>', "</li>\n  ", $html);
        
        $html = str_replace('<div>', "<div>\n  ", $html);
        $html = str_replace('</div>', "</div>\n  ", $html);

        $html = str_replace('<br />', "<br/>\n  ", $html);
        $html = str_replace('<br  />', "<br/>\n  ", $html);
        $html = str_replace('<br>', "<br>\n  ", $html);
        $html = str_replace('<br/>', "<br/>\n  ", $html);
        $html = str_replace('<BR>', "<BR>\n  ", $html);
        $html = str_replace('<BR>', "<BR/>\n  ", $html);
        if (!empty($allowable_tags)) {
            $html = strip_tags($html, $allowable_tags);
        } else {
            $html = strip_tags($html);
        }
        $html = html_entity_decode($html);
        return $html;
    }

    /**
     * 抓取图片
     *
     * @param string $url url
     * @param string $filename 文件名
     */
    function GrabImage($url, $filename)
    {
        if ($url=="") {
            return false;
        }
        loadLib('FileTypeUtil');
        // 检查文件后缀
        if (!FileTypeUtil::validate_img_ext($url)) {
            return false;
        }
        ob_start();
        if (stripos($url, 'https://') === 0) {
            $context  = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false
                )
            );
            readfile($url, false, stream_context_create($context));
        } else {
            readfile($url);
        }
        $img = ob_get_contents();
        ob_end_clean();
        $size = strlen($img);

        $fp2 = fopen($filename, "w");
        fwrite($fp2, $img);
        fclose($fp2);
        // 检查文件 mine type
        if (file_exists($filename)&&!FileTypeUtil::validate_img_file($filename)) {
            return false;
        }

        return $filename;
    }

    /**
     * 获取删除链接验证token
     *
     * @param string $str 被加密的字符串
     * @author robertyang
     * @return string 加密后的token
     */
    function get_delete_url_token($str)
    {
        if (strlen($str) > 32) {
            //超过32位，则先用md5
            $str = md5($str);
        }
        return sha1($str . DELETE_PRIVATE_KEY);
    }

    function _load($path, $name)
    {
        if (!is_null($name) && !class_exists($name)) {
            $className = $name;
            $name = Inflector::underscore($name);
            if (file_exists($path . $name . '.php')) {
                require_once($path . $name . '.php');
                if (phpversion() < 5 && function_exists("overload")) {
                    overload($className);
                }
                return true;
            }
            return false;
        } else {
            return true;
        }
    }

    function _load_dir($dir)
    {
        $handle = opendir($dir);
        while (false !== ($file_name = readdir($handle))) {
            if ($file_name == '.' || $file_name == '..') {
                continue;
            }
            if (is_dir($dir.DS.$file_name)) {
                continue;
            }
            require_once($dir.DS.$file_name);
        }
    }

    function loadLib($name = null)
    {
        $path = APP . 'libs' . DS;
        return _load($path, $name);
    }

    function loadPresenter($name = null)
    {
        $path = APP . 'presenters' . DS;
        return _load($path, $name);
    }

    function loadPluginPresenter($name = null, $plugin = null)
    {
        if (!class_exists(Inflector::camelize($name))) {
            $path = APP . 'plugins' . DS . $plugin . DS . 'presenters'. DS;
            return _load($path, $name);
        }
    }

    function loadService($name = null)
    {
        $dir = '';
        if ($name != null && strpos($name, '.') !== false) {
            list($dir, $name) = explode('.', $name);
            $dir .= DS;
        }
        if (!class_exists(Inflector::camelize($name))) {
            $path = APP . 'services' . DS . $dir;
            return _load($path, $name);
        }
    }

    function newLoadService($name = null)
    {
        $dir = '';
        if ($name != null && strpos($name, '/') !== false) {
            $dir_name = explode('/', $name);
            $dir = $dir_name[0] . DS;
            // 支持二级目录
            if (!empty($dir_name[2])) {
                $dir = $dir . $dir_name[1] . DS;
            }
            $name = end($dir_name);
            unset($dir_name);
        }
        if (!class_exists(Inflector::camelize($name))) {
            $path = APP . 'services' . DS . $dir;
            return _load($path, $name);
        }
    }

    function newLoadPluginService($name = null, $plugin = null)
    {
        $dir = '';
        if ($name != null && strpos($name, '/') !== false) {
            $dir_name = explode('/', $name);
            $dir = $dir_name[0] . DS;
            $name = end($dir_name);
            unset($dir_name);
        }
        if (!class_exists(Inflector::camelize($name))) {
            $path = APP . 'plugins' . DS . $plugin . DS . 'services'. DS . $dir;
            return _load($path, $name);
        }
    }

    function loadInterface($name=null)
    {
        $dir = '';
        if ($name != null && strpos($name, '.') !== false) {
            list($dir, $name) = explode('.', $name);
            $dir .= DS;
        }
        if (!class_exists(Inflector::camelize($name))) {
            $path = APP . 'interfaces' . DS . $dir;
            return _load($path, $name);
        }
    }

    function loadPluginService($name = null, $plugin = null)
    {
        if (!class_exists(Inflector::camelize($name))) {
            $path = APP . 'plugins' . DS . $plugin . DS . 'services'. DS;
            return _load($path, $name);
        }
    }

    function loadPluginServices($plugin)
    {
        $dir = APP.'plugins'.DS.$plugin.DS.'services'.DS;
        _load_dir($dir);
    }

    function loadDataObject($name)
    {
        $path = APP . 'data_objects' . DS;
        return _load($path, $name);
    }

    function loadPluginDataObject($name, $plugin)
    {
        if (!class_exists(Inflector::camelize($name))) {
            $path = APP . 'plugins' . DS . $plugin . DS . 'services'. DS;
            return _load($path, $name);
        }
    }

    function loadTObjectLib($name = null)
    {
        $path = APP . 'plugins' . DS . 'tobject' . DS . 'libs' . DS;
        if (strpos($name, '/') !== false) {
            $path .= dirname($name) . DS;
            $name = basename($name);
        }
        return _load($path, $name);
    }

    function loadTObjectLibs()
    {
        $dir = APP . 'plugins' . DS . 'tobject' . DS . 'libs' . DS;
        _load_dir($dir);
    }

    function array_unset(&$arr, $value)
    {
        if (empty($arr) || !is_array($arr)) {
            return;
        }
        foreach ($arr as $k=>$v) {
            if ($v === $value || $k === $value) {
                unset($arr[$k]);
            }
        }
    }

    function array_unsets(&$arr, $unset_array)
    {
        foreach ($unset_array as $key => $value) {
            array_unset($arr, $value);
        }
    }

    /**
     * @param string $nicks
     * @todo 从人名字符串中去空和去重
     * @return 去重去空后的string
     */
    function remove_empty_and_unique_nick_from_string($nicks)
    {
        if (empty($nicks)) {
            return $nicks;
        }

        if (!is_array($nicks)) {
            $nick_array = explode(";", $nicks);
        } else {
            $nick_array = $nicks;
        }
        array_unset($nick_array, '');
        $nick_array = array_unique($nick_array);
        $nicks = implode(";", $nick_array);
        return $nicks;
    }

function remove_empty_array($arr)
{
    foreach ($arr as $key => $value) {
        if (empty($value)) {
            unset($arr[$key]);
        }
    }
    return $arr;
}


    /**
    *将打包下载的限制大小以格式化形式返回
    *@param $unit 返回的格式，可选值：B,K,M,G,T,P
    *@author voladozhang
    */
    function get_download_all_zip_limit_size($unit = 'B')
    {
        $type = array( 'B','K','M','G','T','P');
        if (!in_array($unit, $type)) {
            $unit = 'B';
        }
        //若定义为none，则以10PB规格返回
        $size = (DOWNLOAD_ALL_ZIP_FILE_LIMIT_SIZE == 'none') ? '10PB' : DOWNLOAD_ALL_ZIP_FILE_LIMIT_SIZE;
        preg_match('/^(\d+)\s?(\w?)B?$/i', $size, $o);
        if (strtoupper($o[2]) == strtoupper($unit)) {
            return $o[1];
        } // 单位相等，直接返回数字
        $type = array_flip($type);
        $mult = $type[$o[2]] - $type[$unit];
        $num = $o[1] * pow(1024, $mult);
        return $num;
        // return $num."$unit";
    }

    //格式化时间
    function timeago($ptime)
    {
        $ago = 1;
        $exact = 2;
        $ptime = strtotime($ptime);
        $etime = time() - $ptime;
        if ($etime < 1) {
            return '刚刚';
        }
        $ymd = date('Y-m-d', $ptime);
        $interval = array(
            12 * 30 * 24 * 60 * 60  =>  array('type' => $exact, 'value' => $ymd),
            30 * 24 * 60 * 60	   =>  array('type' => $exact, 'value' => $ymd),
            7 * 24 * 60 * 60		=>  array('type' => $exact, 'value' => $ymd),
            24 * 60 * 60			=>  array('type' => $ago, 'value' => '天前'),
            60 * 60				 =>  array('type' => $ago, 'value' => '小时前'),
            60					  =>  array('type' => $ago, 'value' => '分钟前'),
            1					   =>  array('type' => $ago, 'value' => '秒前')
        );
        foreach ($interval as $secs => $info) {
            $d = $etime / $secs;
            if ($d >= 1) {
                $r = round($d);
                return $info['type'] == $ago ? ($r . $info['value']) : $info['value'];
            }
        };
    }

    //时间差
    function time_diff($begin_time, $end_time)
    {
        if ($begin_time < $end_time) {
            $starttime = $begin_time;
            $endtime = $end_time;
        } else {
            $starttime = $end_time;
            $endtime = $begin_time;
        }
        $timediff = $endtime - $starttime;
        $days = intval($timediff / 86400);
        $remain = $timediff % 86400;
        $hours = intval($remain / 3600);
        $remain = $remain % 3600;
        $mins = intval($remain / 60);
        $secs = $remain % 60;
        $res = array( 'day' => $days, 'hour' => $hours, 'min' => $mins, 'sec' => $secs );
        return $res;
    }

    function time_diff_string($begin_time, $end_time)
    {
        $res = time_diff($begin_time, $end_time);
        $ret_str = '';
        $ret_str .= $res['day'] > 0 ? $res['day'] . '天' : '';
        $ret_str .= $res['hour'] > 0 ? $res['hour'] . '小时' : '';
        $ret_str .= $res['min'] > 0 ? $res['min'] . '分钟' : '';
        $ret_str .= $res['sec'] > 0 ? $res['sec'] . '秒' : '';
        return $ret_str;
    }

    function time_string_ms($duration)
    {
        $h = intval(($duration / (1000 * 60 * 60)) % 24);
        $m = intval(($duration / (1000 * 60)) % 60);
        $s = intval(($duration / 1000) % 60);
        $ms = intval($duration % 1000);
        $str = "";

        // 小于一分钟
        if ($h == 0 && $m == 0) {
            if ($s == 0) {
                if ($ms != 0) {
                    $str .= ($ms . 'ms');
                }
 
                return $str;
            } else {
                $str .= ($duration / 1000) . 's';
                return $str;
            }
        } else {
            if ($h != 0) {
                $str .= $h . 'h';
            }
            if ($m != 0) {
                $str .= $m . 'm';
            }
            if ($s != 0) {
                $str .= $s . 's';
            }
        }
        return $str;
    }

/**
* 获取人性化的时间提示只返回类似：1天前，一个月前等，应用于WCE
*
* @author sunsonliu
*/
function get_date_ch($time, $with_his = true)
{
    $time_num = strtotime($time);
    $time_d = date('d', $time_num);
    $current_d = date('d', time());
    //日期跨年
    if (date('Y', time()) !== date('Y', $time_num)) {
        return date('Y-m-d', $time_num);
    }

    if ($current_d == $time_d && $time_num > time()-24*60*60) {
        if ($time_num > time()-5*60) {
            return __('刚刚', true);
        } elseif ($time_num > time()-10*60) {
            return __('10分钟前', true);
        } elseif ($time_num > time()-35*60) {
            return __('半小时前', true);
        } elseif ($time_num > time()-50*60) {
            return __('45分钟前', true);
        } elseif ($time_num > time()-1.5*60*60) {
            return __('1小时前', true);
        } else {
            return __('今天', true).' '.date('H:i', $time_num);
        }
    }
    if ($current_d == $time_d+1 && $time_num > time()-2*24*60*60) {
        return $with_his ? __('昨天', true).' '.date('H:i', $time_num) : __('昨天', true);
    }
    if ($current_d == $time_d+2 && $time_num > time()-3*24*60*60) {
        return $with_his ? __('前天', true).' '.date('H:i', $time_num) : __('前天', true);
    }
    if ($time_num >= time()-7*24*60*60 && $time_num < time()-3*24*60*60) {
        // return $with_his ? __('3天前', true).' '.date('H:i', $time_num) : __('3天前', true);
        return __('3天前', true);
    }
    if ($time_num >= time()-14*24*60*60 && $time_num < time()-7*24*60*60) {
        // return $with_his ? __('1周前', true).' '.date('H:i', $time_num) : __('1周前', true);
        return __('1周前', true);
    }
    $date_m = date('m', $time_num);
    if ($date_m[0] == '0') {
        $date_m = $date_m[1];
    }
    $date_d = date('d', $time_num);
    $date_hi = date('H:i', $time_num);
    return  $date_m.__('月', true).$date_d.__('日', true);
    // return __('很久之前', true);
}

    function trim_value(&$value)
    {
        $value = trim($value);
    }

    function tapd_in_array($needle, $haystack)
    {
        if (!empty($haystack)) {
            foreach ($haystack as $h) {
                if (0 == strcmp((string)$h, (string)$needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    function tapd_long_int_equal($long_id_1, $long_id_2)
    {
        if (is_array($long_id_1) || is_array($long_id_2)) {
            return false;
        }
        if (0 == strcmp((string)$long_id_1, (string)$long_id_2)) {
            return true;
        }
        return false;
    }

    function tapd_array_search($needle, $haystack, $return_type = 'pos')
    {
        if (empty($haystack)) {
            return -1;
        }
        $pos = 0;
        foreach ($haystack as $k => $h) {
            if (0 == strcmp((string)$h, (string)$needle)) {
                if ('pos' == $return_type) {
                    return $pos;
                } else {
                    return $k;
                }
            }
            $pos ++;
        }
        return -1;
    }

    function tapd_array_key_search($needle, $haystack)
    {
        if (empty($haystack)) {
            return -1;
        }
        $pos = 0;
        foreach ($haystack as $k => $h) {
            if (0 == strcmp((string)$k, (string)$needle)) {
                return $pos;
            }
            $pos ++;
        }
        return -1;
    }

    function tapd_array_key_search_recursive($needle, $haystack)
    {
        if (empty($haystack)) {
            return -1;
        }
        $pos = 0;
        foreach ($haystack as $k => $h) {
            if (is_array($h)) {
                $pos = tapd_array_key_search_recursive($needle, $h);
                if (-1 != $pos) {
                    return $pos;
                }
            }
            if (0 == strcmp((string)$k, (string)$needle)) {
                return $pos;
            }
            $pos ++;
        }
        return -1;
    }

    function deal_id($id, $length)
    {
        $id = str_replace('-', '', $id);
        $len = strlen($id . '');
        $tmp_str = '';
        for ($i = 0; $i < $length-$len; $i++) {
            $tmp_str = $tmp_str . '0';
        }
        return $tmp_str . $id;
    }

    //将19位的长ID格式化成原来的短ID，用来显示
    function format_long_id($id, $table_name='')
    {
        loadLib('IdProvider');
        return IdProvider::get_short_id_from_long_id($id, $table_name);
    }

    function format_long_id_to_str($id, $table_name='')
    {
        loadLib('IdProvider');
        return IdProvider::format_long_id_to_short_id($id, $table_name);
    }

    //从19位的长ID中截取workspace_id
    function get_workspace_id_from_long_id($id, $table_name='')
    {
        loadLib('IdProvider');
        return IdProvider::get_workspace_id_from_long_id($id, $table_name);
    }

    /**
     * 返回任务支持的状态
     *
     * @author joeyue
     * @return array
     */
    function get_task_status()
    {
        return array(STATUS_OPEN => t(ucfirst(STATUS_OPEN), '/task'),
                     STATUS_PROGRESSING => t(ucfirst(STATUS_PROGRESSING), '/task'),
                     STATUS_DONE => t(ucfirst(STATUS_DONE), '/task'));
    }

    function get_board_card_status()
    {
        return array(STATUS_OPEN => t(STATUS_OPEN, '/board'),
                     STATUS_DONE => t(STATUS_DONE, '/board'));
    }

    /**
     * 在项目间移动业务对象时id的修改
     *
     * @author johnwu
     */
    function move_obj_id_between_projects($old_id, $old_project_id, $new_project_id, $table_name='')
    {
        loadLib('IdProvider');
        return IdProvider::change_workspace_in_id($old_id, $new_project_id, $table_name);
    }

    function array_recursive(&$array, $fun, $apply_to_keys=false)
    {
        static $recursive_counter = 0;
        if (++$recursive_counter > 1000) {
            die('possiable deep recursion attack');
        }
        foreach ($array as $key=>$value) {
            if (is_array($value)) {
                array_recursive($array[$key], $fun, $apply_to_keys);
            } else {
                $array[$key] = $fun($value);
            }
            if ($apply_to_keys) {
                $new_key = $fun($key);
                if ($new_key != $key) {
                    $array[$new_key] = $array[$key];
                    unset($array[$key]);
                }
            }
        }
        $recursive_counter--;
    }

    function tapd_unserialize($serialized, &$into)
    {
        static $sfalse;
        if (null === $sfalse) {
            $sfalse = unserialize(false);
        }
        $into = @unserialize($serialized);
        if ($into !== false || rtrim($serialized) === $sfalse) {
            return true;
        } else {
            $into = $serialized;
            return false;
        }
    }

    /**
     * 安全的解序列化方法
     *
     * @author powerjiang
     * @access public
     */
    function safe_unserialize($serialized)
    {
        // unserialize will return false for object declared with small cap o
        // as well as if there is any ws between O and :
        if (is_string($serialized) && strpos($serialized, "\0") === false) {
            if (strpos($serialized, 'O:') === false || !preg_match('/(^|;|{|})O:[0-9]+:"/', $serialized)) {
                // the easy case, nothing to worry about
                // let unserialize do the job
                return @unserialize($serialized);
            }
        }
        return false;
    }

    function is_long_id($id, $table_name='')
    {
        loadLib('IdProvider');
        return IdProvider::is_valid_long_id($id, $table_name);
    }

    function _array_add_perfix_to_long_id_key($a)
    {
        $a_copy = array();
        foreach ($a as $index=>$value) {
            if (is_long_id($index)) {
                $index = 'TAPDLONGID_'.$index;
            }
            $a_copy[$index] = $value;
        }
        return $a_copy;
    }

    function tapd_array_merge($a, $b)
    {
        $a = _array_add_perfix_to_long_id_key($a);
        $b = _array_add_perfix_to_long_id_key($b);
        $m_a_b =  array_merge($a, $b);
        $m_a_b_copy = array();
        foreach ($m_a_b as $index=>$value) {
            if (str_begin_with($index, 'TAPDLONGID_')) {
                $index = str_replace('TAPDLONGID_', '', $index);
            }
            $m_a_b_copy[$index] = $value;
        }
        return $m_a_b_copy;
    }

    /**
     * 由短ID转为长ID
     * 注：内外网情况下，该函数是无法正确转换长短ID，将废弃。
     */
    function short_id_to_long_id($workspace_id, $short_id, $table_name='')
    {
        loadLib('IdProvider');
        return IdProvider::short_id_to_long_id($workspace_id, $short_id, $table_name);
    }

    function change_id_pre($id, $table_name='')
    {
        loadLib('IdProvider');
        return IdProvider::change_in_out_flag_in_id($id, $table_name);
    }

    /**
     * 处理类似|1010030831003675931|1010030831003675932 或者1010030831003675931,1010030831003675931这样的长ID
    */
    function format_other_long_id($split, $ids)
    {
        $ret = trim($ids, $split);
        $id_array = explode($split, $ret);
        $rets = array();
        foreach ($id_array as $id) {
            $rets[] = format_long_id($id);
        }
        $ret = $split . implode($split, $rets);
        return $ret;
    }

    function clear_menu_cache($workspace_id, $level_start = 1, $level_end = 10)
    {
        // if (new_menu_data_enabled($workspace_id)) {
        loadService('MenuDataService');
        MenuDataService::clean_workspace_menu_cache($workspace_id);
        // }
        for ($i = $level_start; $i <= $level_end; $i ++) {
            $cache_key = CacheKeyGenerator::generate_key('menu', $workspace_id . '_' . $i);
            MyCache::delete($cache_key);
        }
    }

    function clear_external_user_chooser_cache($workspace_id)
    {
        if (IS_CLOUD) {
            g('CacheManagerService')->update_script_cache_version("userspinyin_" . $workspace_id);
        } else {
            $cache_key = CacheKeyGenerator::generate_key('EXTERNAL_USERCHOOSER_'.COMPRESS_VERSION.'_', intval($workspace_id));
            MyCache::delete($cache_key);
        }
    }

    function init_external_user_chooser_cache($workspace_id)
    {
        $version = time();
        $cache_key = CacheKeyGenerator::generate_key('EXTERNAL_USERCHOOSER_'.COMPRESS_VERSION.'_', intval($workspace_id));
        MyCache::add($cache_key, $version, '+1 month');
    }

    function get_user_chooser_version_from_cache($workspace_id)
    {
        if (IS_CLOUD) {
            $version_key = 'userspinyin_' . $workspace_id . '_version';
            $user_chooser_version = g('CacheProvider')->get(CacheDomainType::T_DEFAULT, $version_key, 0);
            if (empty($user_chooser_version)) {
                $user_chooser_version = 0;
                g('CacheProvider')->add(CacheDomainType::T_DEFAULT, $version_key, 0, '+1 month');
            }
        } else {
            $user_chooser_version_cache_key = CacheKeyGenerator::generate_key('EXTERNAL_USERCHOOSER_'.COMPRESS_VERSION.'_', intval($workspace_id));
            $user_chooser_version = MyCache::get($user_chooser_version_cache_key);
            if (empty($user_chooser_version)) {
                init_external_user_chooser_cache($workspace_id);
                $user_chooser_version = MyCache::get($user_chooser_version_cache_key);
            }
        }
        return $user_chooser_version;
    }

    function clear_external_user_chooser_all_cache()
    {
        $cache_key = 'EXTERNAL_USERCHOOSER_ALL_'.COMPRESS_VERSION;
        MyCache::delete($cache_key);
    }

    function init_external_user_chooser_all_cache()
    {
        $version = time();
        $cache_key = 'EXTERNAL_USERCHOOSER_ALL_'.COMPRESS_VERSION;
        MyCache::add($cache_key, $version, '+1 month');
    }

    function clear_internal_user_chooser_all_cache()
    {
        $cache_key = 'INTERNAL_USERCHOOSER_ALL_'.COMPRESS_VERSION;
        MyCache::delete($cache_key);
    }

    function init_internal_user_chooser_all_cache()
    {
        $version = time();
        $cache_key = 'INTERNAL_USERCHOOSER_ALL_'.COMPRESS_VERSION;
        MyCache::add($cache_key, $version, '+1 month');
    }

    /**
     *
     * 判断是否是外包人员
     * 主要用在外网首页判断
     */
    function is_outsource_usr($nick)
    {
        if ((substr($nick, -3, 3) == '_ex') || (strtolower(substr($nick, 0, 2)) == 'v_') || (strtolower(substr($nick, 0, 2)) == 'l_')) {
            return true;
        }
        return false;
    }

    function array_id_instead_of_index($model_name, $res, $field='id')
    {
        $nres = array();
        foreach ($res as $key=>$val) {
            $val = $val[$model_name];
            $field_val = $val[$field];
            $nres[$field_val] = $val;
        }
        return $nres;
    }

    function repalce_first_and_mark_to_question_mark_in_url($url)
    {
        $and_mark_index = strpos($url, '&');
        if (strpos($url, '?') === false && $and_mark_index !== false) {
            $url = substr_replace($url, "?", $and_mark_index, 1);
        }
        return $url;
    }

    function new_menu_data_enabled($workspace_id)
    {
        if (empty($workspace_id)) {
            return false;
        }

        if (is_array($workspace_id)) {
            $workspace_id = array_shift($workspace_id);
        }

        if (g('menu/MenuAbtestService')->is_hit($workspace_id)) {
            return true;
        }
        return false;
    }

    /*
    * empty() 返回 FALSE。换句话说，""、0、"0"、NULL、FALSE、array()、var $var; 以及没有任何属性的对象都将被认为是空的，
    * tempty()则不认为 0、"0"两种情况为空，其它与empty相同
    * @author frankychen
    * @access public
    */
    function tempty($var)
    {
        if ($var === 0 || $var === '0') {
            return false;
        } else {
            return empty($var);
        }
    }

    /*
    * empty() 返回 FALSE。换句话说，""、0、"0"、NULL、FALSE、array()、var $var; 以及没有任何属性的对象都将被认为是空的，
    * 同时 is_content_null() 认为所有只有键值而值为空的数组也是空的，不认为 0、"0"两种情况为空。
    * @author terrysxu
    * @access public
    */
    function is_content_null($var)
    {
        if ($var === 0 || $var === '0') {
            return false;
        } elseif (empty($var)) {
            return true;
        } elseif (is_array($var)) {
            foreach ($var as $value) {
                if (!is_content_null($value)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    function get_workspace_name($id)
    {
        return g('Workspace', 'Model')->get_workspace_name($id);
    }

    function get_workspace_pretty_name($id)
    {
        $return = g('Workspace')->get_workspace_map($id);
        return isset($return[$id]['pretty_name']) ? $return[$id]['pretty_name'] : '';
    }

    /**
     * 递归遍历数组，获取key对应的value，不存在则返回false，对大小写不敏感
     *
     * @author sunsonliu
     */
    function get_value_by_key_from_array($key, $array)
    {
        if (empty($array) || !is_array($array)) {
            return false;
        }
        $ret = false;
        foreach ($array as $_key => $_value) {
            if (strcasecmp($_key, $key) == 0) {
                if (!$_value) {
                    $ret = '';
                } else {
                    $ret = $_value;
                }
                break;
            }
            if (is_array($_value)) {
                $ret = get_value_by_key_from_array($key, $_value);
            }
        }
        return $ret;
    }

    /**
     * 判断是否发送消息,$name为空时返回该项目下所有的开关
     *
     * @param $name 操作标识，如rtx::bug::create
     * @author sunsonliu
     */
    function get_inform_settings($workspace_id, $name = '')
    {
        loadModel('Setting');
        $setting = new Setting();
        $condition = array('project_id' => $workspace_id, 'type' => 'inform_settings');
        if (empty($name)) {
            $settings = $setting->findAll($condition, 'name, value');
            $ret = array();
            foreach ((array)$settings as $key => $value) {
                $ret [$value['Setting']['name']] = $value['Setting']['value'];
            }
            return $ret;
        } else {
            $condition = array_merge($condition, array('name' => $name));
            $ret = $setting->find($condition, 'value');
            return $ret['Setting']['value'] == 1;
        }
    }

    /**************************************************************
     *
     *  使用特定function对数组中所有元素做处理
     *  @param  string  &$array	 要处理的字符串
     *  @param  string  $function   要执行的函数
     *  @return boolean $apply_to_keys_also	 是否也应用到key上
     *  @access public
     *
     *************************************************************/
    function array_exec_func_recursive(&$array, $function, $apply_to_keys_also = false)
    {
        static $recursive_counter = 0;
        if (++$recursive_counter > 1000) {
            die('possible deep recursion attack');
        }
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                array_exec_func_recursive($array[$key], $function, $apply_to_keys_also);
            } else {
                $array[$key] = $function($value);
            }

            if ($apply_to_keys_also && is_string($key)) {
                $new_key = $function($key);
                if ($new_key != $key) {
                    $array[$new_key] = $array[$key];
                    unset($array[$key]);
                }
            }
        }
        $recursive_counter--;
    }

    /**************************************************************
     *
     *  将数组转换为JSON字符串（兼容中文）
     *  @param  array   $array	  要转换的数组
     *  @return string	  转换得到的json字符串
     *  @access public
     *
     *************************************************************/
    function json_array($array)
    {
        array_exec_func_recursive($array, 'urlencode', true);
        $json = json_encode($array);
        return urldecode($json);
    }

    /**
     * json转数组
     */
    function json_to_array($web)
    {
        $arr=array();
        foreach ($web as $k=>$w) {
            if (is_object($w)) {
                $arr[$k]=json_to_array($w);
            }  //判断类型是不是object
            else {
                $arr[$k]=$w;
            }
        }
        return $arr;
    }


    /**
     * unicode转中文
     */
    function unicode_to_string($str, $encoding=null)
    {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/u', create_function('$match', 'return mb_convert_encoding(pack("H*", $match[1]), "utf-8", "UTF-16BE");'), $str);
    }

    /**
     * @todo get url parameters.
     * @author clarkhu
     */
    function get_url_query_params($url)
    {
        $url_parse = parse_url($url);
        $url_params_str = isset($url_parse['query']) ? $url_parse['query'] : '';
        $url_params_arr = explode('&', $url_params_str);
        $url_parameters = array();
        foreach ($url_params_arr as $url_param) {
            $url_para = explode('=', $url_param);
            $url_parameters[$url_para[0]] = !empty($url_para[1])? $url_para[1] : '';
        }
        return $url_parameters;
    }

    /**
     * 立刻刷写session到memcached
     * @return
     */
    function flash_session()
    {
        if (!IS_ENABLE_SESSION_LOCK) {
            session_write_close();
            session_start();
        }
    }


    function debug_trace($is_return = false, $new_line_mark = "\n")
    {
        $trace = debug_backtrace();
        $str = $new_line_mark;
        $tmpl = array('line'=>'', 'file'=>'', 'function'=>'', 'class'=>'');
        foreach ($trace as $v) {
            $v  = array_merge($tmpl, $v);
            $str .= "file:{$v['file']}  line:{$v['line']}  function:{$v['function']} class:{$v['class']} {$new_line_mark}";

            // $str .= "";
        }
        unset($trace);
        if ($is_return) {
            return $str;
        } else {
            if (DEBUG >= 1) {
                echo $str;
            }
        }
    }

    /**
     * @todo convert array to string
     * @author clarkhu
     */
    function change_string_connection_character($string, $src_connection_character = ';', $new_connection_character = ',')
    {
        $string_arr = explode(';', $string);
        $workitem_owner = explode(';', $string);
        if (!empty($string_arr)) {
            foreach ($string_arr as $key=> $string_value) {
                if (empty($string_value)) {
                    unset($string_arr[$key]);
                }
            }
        }
        $new_string = implode(',', $string_arr);
        return $new_string;
    }

    function space($num)
    {
        $html = '';
        for ($i=0; $i < $num; $i++) {
            $html .= '&nbsp;';
        }
        return '<span style="font-family:Arial;">' . $html . '</span>';
    }


    function revert_plus($str)
    {
        return str_replace("@@@***@@@", "+", $str);
    }

    function revert_slash($str)
    {
        return str_replace("@@@^^^@@@", "/", $str);
    }

    function is_attachment_empty($data)
    {
        $ret = false;
        if (!empty($data['Attachment'])) {
            foreach ($data['Attachment'] as $key => $value) {
                if (is_array($value)) {
                    if (isset($value['name']) && !empty($value['name'])) {
                        $ret = true;
                        break;
                    }
                }
            }
        }
        return $ret;
    }

    /**
     * t die
     */
    function t_die($msg = '')
    {
        print "\n<pre class=\"cake_debug\" style=\"text-align:left\">\n";
        $calledFrom = debug_backtrace();
        print "\n\r";
        echo '<strong>[die]:</strong>'. substr(str_replace(ROOT, '', $calledFrom[1]['file']), 1) ;
        echo ' (line ' . $calledFrom[1]['line'] . ')';
        print "\n";
        ob_start();
        if ($msg) {
            print_r('<strong>[msg]:</strong>'.$msg);
        }
        $var = ob_get_clean();
        print "{$var}\n</pre>\n";
        unset_exit_overload();
        die();
    }
    /**
     * 如果需要追查die,exit,可以启用以下这段
     */
//	if(ISDEV && false) {
//		set_exit_overload('t_die');
//	}

    function get_first_readable_char($str)
    {
        $pa = '/[\x{4e00}-\x{9fa5}0-9a-zA-Z]+/u';
        $len = mb_strlen($str, 'UTF-8');
        $index = 0;
        while ($index < $len) {
            $char = mb_substr($str, $index, 1, 'UTF-8');
            if (preg_match($pa, $char)) {
                return $char;
            }
            $index++;
        }
        return "T";
    }


    /**
     * 由于debug函数无法输出变量类型与大小
     * 自定义调试函数，fatedlai修改自thinkphp框架的dump函数
     *
     * 浏览器友好的变量输出
     * @param mixed $var 变量
     * @param boolean $echo 是否输出 默认为True 如果为false 则返回但不输出
     * @param string $label 标签 默认为空
     * @param boolean $strict 是否严谨 默认为true 如果为false 则采用print_r输出
     * @return void|string
     * @example dump($var); dump($var, 0); dump($var, 0, 1, '<pre>');
     */
    function dump($var, $strict=true, $echo=true, $label=null)
    {
        $label = ($label === null) ? '' : rtrim($label) . ' ';
        $calledFrom = debug_backtrace();
        $trace = '<strong>' . substr(str_replace(ROOT, '', $calledFrom[0]['file']), 1) . '</strong>' . ' (line <strong>' . $calledFrom[0]['line'] . '</strong>)';
        if (!$strict) {
            if (ini_get('html_errors')) {
                $output = print_r($var, true);
                $output = $trace . '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES) . '</pre>';
            } else {
                $output = $trace . $label . print_r($var, true);
            }
        } else {
            ob_start();
            var_dump($var);
            $output = ob_get_clean();
            if (!extension_loaded('xdebug')) {
                $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', $output);
                $output = '<pre>' . $label . htmlspecialchars($output, ENT_QUOTES) . '</pre>';
                $output = $trace . $output;
            }
        }
        if ($echo) {
            echo($output);
            return null;
        } else {
            return $output;
        }
    }

    function tcloud_token($email)
    {
        return md5($email . 'tAPdclound');
    }

    function company_invite_link_token()
    {
        return md5(current_user('id') . time() . 'tApd^');
    }

    //生成随机token
    function generate_token($str = '', $salt = 'tApd^')
    {
        return md5($str . time() . $salt);
    }

    function human_filesize($bytes, $decimals = 2)
    {
        $sz = 'BKMGTP';
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
    }

    // 云端base64显示图片.
    function base64pic($img_src, $alt='')
    {
        $picture = '<img src="' . $img_src . '"/>';
        // if (!IS_CLOUD){
        // 	$picture = '<img src="' . $img_src . '"/>';
        // } else {
        // 	$paths = explode(".", $img_src);
        // 	$ext = strtolower($paths[count($paths)-1]);
        // 	switch ($ext) {
        // 		case 'gif':
        // 			$image_type = 'image/gif';
        // 			break;
        // 		case 'png':
        // 			$image_type = 'image/png';
        // 			break;
        // 		case 'ico':
        // 			$image_type = 'image/x-icon';
        // 			break;
        // 		case 'jpg':
        // 			$image_type = 'image/jpeg';
        // 		default:
        // 			break;
        // 	}
        // 	$img_base64 = base64_encode(file_get_contents($img_src));
        // 	$picture = '<img src="data:' . $image_type . ';base64,' . $img_base64 . '"  alt="' . $alt . '" />';
        // }
        return $picture;
    }

    function get_class_define_file($class_name)
    {
        $reflection = new ReflectionClass($class_name);
        return $reflection->getFileName();
    }

    //获取当前url voladozhang
    function cur_page_url()
    {
        $pageURL = 'http';

        if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {
            $pageURL .= "s";
        }
        $pageURL .= "://";

        $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
        return $pageURL;
    }

    //根据配置 给指定的url添加uri后缀 用于开门神白名单  voladozhang
    function fix_url_with_menshen_security_suffix($url = '')
    {
        $enable_subffix = defined('ENABLE_MENSHEN_SECURITY_SUFFIX') && ENABLE_MENSHEN_SECURITY_SUFFIX;
        if ($enable_subffix) {
            $url_suffix = defined('MENSHEN_SECURITY_SUFFIX') ? MENSHEN_SECURITY_SUFFIX : '';
            if (!empty($url) && !empty($url_suffix)) {
                if (strpos($url, '?') !== false) {
                    $url_parts = explode('?', $url);
                    $url_parts[0] .= $url_suffix;
                    $url = implode('?', $url_parts);
                } else {
                    $url .= $url_suffix;
                }
            }
        }
        return $url;
    }

    //for js
    function get_menshen_security_suffix()
    {
        $enable_subffix = defined('ENABLE_MENSHEN_SECURITY_SUFFIX') && ENABLE_MENSHEN_SECURITY_SUFFIX;
        if ($enable_subffix) {
            $enable_subffix = defined('MENSHEN_SECURITY_SUFFIX') ? MENSHEN_SECURITY_SUFFIX : '/security';
        }
        return $enable_subffix;
    }

    function is_json($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERRROR_NONE);
    }


if (!function_exists('get_scheme')) {
    function get_scheme()
    {
        return is_https() ? 'https://' : 'http://';
    }
}

function get_system_type($controller = array())
{

    //基础tag
    $tag = 'TAPD';

    if (IS_CLOUD) { //云端tag
        $tag .= '_CLOUD';
    } elseif (IS_EXTERNAL) { //外网tag
        $tag .= '_EXTERNAL';
    }

    vendor('Mobile_Detect');
    $detect = new Mobile_Detect();
    if (isset($controller->plugin) && isset($controller->params['url']['mini']) && $controller->params['url']['mini']==1) {
        $tag .= '_MINI';
    } elseif ($detect->isWeWorkDesktop()) {	//企业微信PC版
        $tag .= '_QYWXPC';
    } elseif ($detect->isWeWork()) {	//企业微信移动端
        $tag .= '_QYWXAPP';
    } elseif (IS_WEIXIN) {	//公众号
        $wx_plugins = array('nwx', 'wx_ass');
        $current_plugin = (isset($controller->plugin) && !empty($controller->plugin)) ? $controller->plugin : '';
        if (in_array($current_plugin, $wx_plugins)) {
            $tag .= '_WECHAT';
            if (IS_CLOUD && isset($controller->params['url']['qy']) && $controller->params['url']['qy']==1) {
                $tag .= '_QY';
            }
        }
    }
    $system_type = $tag;
    return $system_type;
}

function get_feed_creator()
{
    $creator = current_user('nick');
    if (IS_CLOUD) {
        $creator = current_user('id');
    }
    return $creator;
}

function get_forum_creator()
{
    $creator = '';
    if (IS_CLOUD) {
        $creator = isset($_SESSION["forum_user"]['id']) ? $_SESSION["forum_user"]['id'] : '';
    }
    return $creator;
}


/**
 * 检查外网页面展示数据是否有访问内网的错误，有则在页面上加上提示
 * @return boolean
 */
function has_out_tapd_query_for_in_data_error()
{
    if (!IS_EXTERNAL) {
        return false;
    } elseif (IS_EXTERNAL && !IS_CLOUD && isset($GLOBALS['out_tapd_query_for_in_data_error']) && $GLOBALS['out_tapd_query_for_in_data_error']) {
        return true;
    }
    return false;
}

/**
 * 对数组按照一定属性排序
 * @param $arr 要排序的数组
 * @param $sort 排序字段，如‘ID desc’
 * @author sunson
 */
function sort_arr($arr, $sort)
{
    $sort = trim($sort);
    $sort_fun_body = _get_sort_fun_body($sort);
    $sort_fun = create_function('$a, $b', $sort_fun_body);
    usort($arr, $sort_fun);
    return $arr;
}

/**
 * 排序规则的实现部分,根据$sort参数生成对应的函数体
 * @param $sort 排序字段，如‘ID desc’
 * @author sunson
 */
function _get_sort_fun_body($sort)
{
    $sort = explode(' ', $sort);
    $sort_field = $sort[0];
    $sort_type = $sort[1];

    $body = '$a_sort = $a[\''.$sort_field.'\'];';
    $body .= '$b_sort = $b[\''.$sort_field.'\'];';
    $body .= '$is_a_less_b = ($a_sort == $b_sort ? 0 : ($a_sort < $b_sort) ? 1 : -1);';
    $body .= 'return "desc" == "'.$sort_type.'" ? $is_a_less_b : -1 * $is_a_less_b;';
    return $body;
}


/**
 * 输出对url友好的base64编码
 * "="不处理
 */
function base64_encode4url($data)
{
    return strtr(base64_encode($data), '+/', '-_');
}

/**
 * 解析对url友好的base64编码
 * "="不处理
 */
function base64_decode4url($data)
{
    return base64_decode(strtr($data, '-_', '+/'));
}

function write_effort_log($func_name, $workspace_id, $entity_id, $parent_story_id, $entity_type, $data)
{
    require_once(APP . 'libs' . DS . 'monitor_log.php');
    $log = '【'. $func_name . '】' . $workspace_id . ':' . $entity_type . ':' . $entity_id . ':'
        . $parent_story_id . '	'. json_encode($data);
    MonitorLog::write_by_lock_file('workitem_effort.log', $log);
}

function mb_unserialize($serial_str)
{
    $serial_str= preg_replace('!s:(\d+):"(.*?)";!se', "'s:'.strlen('$2').':\"$2\";'", $serial_str);
    $serial_str= str_replace("\r", "", $serial_str);
    return unserialize($serial_str);
}

function get_default_if_empty($str, $default='')
{
    if (empty($str)) {
        return $default;
    } else {
        return $str;
    }
}

/**
* 将一个字串中含有全角的数字字符、字母、空格或'%+-()'字符转换为相应半角字符
* @param string $str 待转换字串
* @return string $str 处理后字串
*/
function make_semiangle($str)
{
    static $semiangle_map;
    if (empty($semiangle_map)) {
        $semiangle_map = [
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
            'Ａ' => 'A', 'Ｂ' => 'B', 'Ｃ' => 'C', 'Ｄ' => 'D', 'Ｅ' => 'E',
            'Ｆ' => 'F', 'Ｇ' => 'G', 'Ｈ' => 'H', 'Ｉ' => 'I', 'Ｊ' => 'J',
            'Ｋ' => 'K', 'Ｌ' => 'L', 'Ｍ' => 'M', 'Ｎ' => 'N', 'Ｏ' => 'O',
            'Ｐ' => 'P', 'Ｑ' => 'Q', 'Ｒ' => 'R', 'Ｓ' => 'S', 'Ｔ' => 'T',
            'Ｕ' => 'U', 'Ｖ' => 'V', 'Ｗ' => 'W', 'Ｘ' => 'X', 'Ｙ' => 'Y',
            'Ｚ' => 'Z', 'ａ' => 'a', 'ｂ' => 'b', 'ｃ' => 'c', 'ｄ' => 'd',
            'ｅ' => 'e', 'ｆ' => 'f', 'ｇ' => 'g', 'ｈ' => 'h', 'ｉ' => 'i',
            'ｊ' => 'j', 'ｋ' => 'k', 'ｌ' => 'l', 'ｍ' => 'm', 'ｎ' => 'n',
            'ｏ' => 'o', 'ｐ' => 'p', 'ｑ' => 'q', 'ｒ' => 'r', 'ｓ' => 's',
            'ｔ' => 't', 'ｕ' => 'u', 'ｖ' => 'v', 'ｗ' => 'w', 'ｘ' => 'x',
            'ｙ' => 'y', 'ｚ' => 'z','（' => '(', '）' => ')', '〔' => '[', '〕' => ']', '【' => '[',
            '】' => ']', '〖' => '[', '〗' => ']', '“' => '[', '”' => ']',
            '‘' => '[', '’' => ']', '｛' => '{', '｝' => '}', '《' => '<','》' => '>',
            '％' => '%', '＋' => '+', '—' => '-', '－' => '-', '～' => '-',
            '：' => ':', '。' => '.', '、' => ',', '，' => '.', '、' => '.',
            '；' => ',', '？' => '?', '！' => '!', '…' => '-', '‖' => '|',
            '”' => '"', '’' => '`', '‘' => '`', '｜' => '|', '〃' => '"','　' => ' '
        ];
    }
    return strtr($str, $semiangle_map);
}

if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        $headers = '';
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

function arr_content_replace($array, $search, $replace)
{
    if (is_array($array)) {
        foreach ($array as $k => $v) {
            $array[$k] = arr_content_replace($array[$k], $search, $replace);
        }
    } else {
        if (!is_null($array)) { //str_replace会产生副作用，将null类型转化为字符串
            $array = str_replace($search, $replace, $array);
        }
    }
    return $array;
}

function search_array_key($array, $result=array(), $prekey='', $key_name='description')
{
    if (!is_array($array)) {
        return $result;
    }
    if (array_key_exists($key_name, $array)) {
        $result[] = trim("{$prekey}.{$key_name}", '.');
    }
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $search_result = search_array_key($array[$key], $result, "{$prekey}.{$key}", $key_name);
            if (!empty($search_result)) {
                $result = $search_result;
            }
        }
    }

    return $result;
}

function replace_desc_field($array, $paths=array())
{
    $empty_array = array();

    foreach ($paths as $keys_str) {
        $var_tmp = '已替换，请查看变更历史';
        $keys = array_reverse(explode('.', $keys_str));

        foreach ($keys as $key) {
            $var_tmp = array($key=>$var_tmp);
        }

        $empty_array = array_merge_recursive($var_tmp, $empty_array);
    }

    return @Set::merge($array, $empty_array);
}

/**
 * 18位身份证验证
 * @param  [type] $idcard [description]
 * @return [type]         [description]
 */
function check_person_id_card($id_card)
{
    // 只能是18位
    if (strlen($id_card)!=18) {
        return false;
    }
    // 取出本体码
    $idcard_base = substr($id_card, 0, 17);
    // 取出校验码
    $verify_code = substr($id_card, 17, 1);
    // 加权因子
    $factor = array(7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2);
    // 校验码对应值
    $verify_code_list = array('1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2');
    // 根据前17位计算校验码
    $total = 0;
    for ($i=0; $i<17; $i++) {
        $total += substr($idcard_base, $i, 1)*$factor[$i];
    }
    // 取模
    $mod = $total % 11;
    // 比较校验码
    if ($verify_code == $verify_code_list[$mod]) {
        return true;
    } else {
        return false;
    }
}

/**
 * 获取类的常量
 *
 * @param  string $class_name 类名
 * @return array | null
 */
function get_class_const($class_name)
{
    if (class_exists($class_name)) {
        $cls = new ReflectionClass($class_name);

        return $cls->getConstants();
    } else {
        return null;
    }
}

function splite_str_to_array($str, $delimiter=',')
{
    return array_unique(
        array_filter(
            array_map(
                'trim',
                explode($delimiter, $str)
            ),
            'strlen'
        )
    );
}

function value_contain_mysql_func($value)
{
    if (preg_match('/[A-Za-z]+\\([a-z0-9]*\\),?/', $value)) {
        return true;
    }
    return false;
}

/**
 * 移除字符串中emoji字符
 * @param string | $text
 * @return string | string
 */
function remove_emoji($text)
{
    return MyClean::cleanUTF8mb4($text);
}

function get_tcube_sdk()
{
    return g('TcubeApiSdk', 'Vendor', array(TCUBE_API_USER, TCUBE_API_PASSWORD));
}

/**
 * 安全输出 json 到页面上
 *
 * JSON_HEX_QUOT " => \u0022
 * JSON_HEX_TAG  < => \u003C  > => \u003E
 * JSON_HEX_AMP  & => \u002
 * JSON_HEX_APOS ' => \u0027
 *
 * @param  [type] $data [description]
 * @return [type]       [description]
 */
function safe_json_encode($data)
{
    return json_encode($data, JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS);
}

function browser()
{
    $browser = 'unknow';

    $agent = $_SERVER["HTTP_USER_AGENT"];

    if (strpos($agent, 'MSIE')!==false || strpos($agent, 'rv:11.0')) {   //ie11判断
        $browser = 'Internet Explorer';
    } elseif (strpos($agent, 'Firefox')!==false) {
        $browser = 'Firefox';
    } elseif (strpos($agent, 'Chrome')!==false) {
        $browser = 'Google Chrome';
    } elseif (strpos($agent, 'Opera')!==false) {
        $browser = 'Opera';
    } elseif ((strpos($agent, 'Chrome')==false)&&strpos($agent, 'Safari')!==false) {
        $browser = 'Safari';
    }
    return $browser;
}

function browser_version()
{
    $browser_version  = 'unknow';

    if (empty($_SERVER['HTTP_USER_AGENT'])) {    //当浏览器没有发送访问者的信息的时候
        $browser_version = 'unknow';
    }
    $agent= $_SERVER['HTTP_USER_AGENT'];
    if (preg_match('/MSIE\s(\d+)\..*/i', $agent, $regs) || //IE
        preg_match('/FireFox\/(\d+)\..*/i', $agent, $regs) || //FF
        preg_match('/Opera[\s|\/](\d+)\..*/i', $agent, $regs) || //opera
        preg_match('/Chrome\/(\d+)\..*/i', $agent, $regs) || //chrome
        ((strpos($agent, 'Chrome')==false)&&preg_match('/Safari\/(\d+)\..*$/i', $agent, $regs)) //safari
    ) {
        $browser_version = $regs[1];
    }

    return $browser_version;
}

function browser_info()
{
    $agent= $_SERVER['HTTP_USER_AGENT'];

    $browser_info = $agent;

    $browser = browser();
    $browser_version = browser_version();

    if ('unknow' != $browser && 'unknow' != $browser_version) {
        $browser_info = $browser .  ' ' . $browser_version;
    }

    return $browser_info;
}

function array_level($arr)
{
    $al = array(0);
    function aL($arr, &$al, $level=0)
    {
        if (is_array($arr)) {
            $level++;
            $al[] = $level;
            foreach ($arr as $v) {
                aL($v, $al, $level);
            }
        }
    }
    aL($arr, $al);
    return max($al);
}

/*
    生成tapdh5前端用的地址（如https://{BASE_PATH}/tapdm/worktable/index），
    参数location为tapdm/后面的部分，即worktable/index
*/
function get_tapdh5_url($location)
{
    $ret = '';
    if (ISDEV) {
        $base = str_replace('/tapd3_cloud', '', BASE_PATH);
        $base = str_replace('/tapd3', '', $base);
        if (IS_CLOUD) {
            if (isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'lion.oa.com')!==false) {
                $ret = $base. 'tapdm/' . $location;
            } else {
                $ret = $base. 'tapdm_cloud/' . $location;
            }
        } else {
            $ret = $base. 'tapdm/' . $location;
        }
    } else {
        $ret = BASE_PATH . 'tapdm/' . $location;
    }
    return $ret;
}

function cake_condition_escape($value, $type='value')
{
    if ($type == 'field') {
        return '{$__cakeIdentifier[' . $value . ']__$}';
    } else {
        return '{$__cakeNoFuncValue[' . $value . ']__$}';
    }
}

function write_log($str, $filename)
{
    $myfile = fopen("/tmp/" . $filename, "a");
    if (!empty($myfile)) {
        fwrite($myfile, $str);
        fclose($myfile);
    }
}

function get_service_class_name($service_name)
{
    $name = explode('.', $service_name);
    $name = end($name);
    $name = explode('/', $name);
    $service_name = end($name);
    return $service_name;
}

function unparse_url($parsed_url)
{
    $scheme		= isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
    $host		= isset($parsed_url['host']) ? $parsed_url['host'] : '';
    $port 		= isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
    $user 		= isset($parsed_url['user']) ? $parsed_url['user'] : '';
    $pass 		= isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
    $pass 		= ($user || $pass) ? "{$pass}@" : '';
    $path 		= isset($parsed_url['path']) ? $parsed_url['path'] : '';
    $query 		= isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
    $fragment	= isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

    return "{$scheme}{$user}{$pass}{$host}{$port}{$path}{$query}{$fragment}";
}

function ksort_recursive(&$array)
{
    foreach ($array as &$value) {
        if (is_array($value)) {
            ksort_recursive($value);
        }
    }

    return ksort($array);
}

function get_href_target()
{
    static $href_target;
    if (!empty($href_target)) {
        return $href_target;
    }
    vendor('Mobile_Detect');
    $detect = new Mobile_Detect();
    if ($detect->isWeWorkDesktop()) {	//企业微信PC版
        $href_target = '_self';
    } else {
        $href_target = '_blank';
    }
    return $href_target;
}

/**
 * 判断ua是不是ie
 * 处理下没有识别出ie11跟edge的逻辑
 * @param  [type]  $ua [description]
 * @return boolean     [description]
 */
function is_ie_browser($ua)
{
    if (preg_match("/MSIE/", $ua) || preg_match('/rv:([\d.]+)\) like Gecko/', $ua) || strpos($ua, 'Edge') !== false) {
        return true;
    } else {
        return false;
    }
}

function get_jumper_url($target)
{
    $url = 'fastapp/jump.php?target=' . urlencode($target);
    if ((defined('IS_CLOUD') && IS_CLOUD) || (defined('ISDEV') && ISDEV)) {
        return (defined('CLOUD_LOGIN_BASH_PATH') ? CLOUD_LOGIN_BASH_PATH : BASE_PATH) . $url;
    } else {
        return url_to_https("http://tapd.tencent.com/") . $url;
    }
}

//获取文档的最新内容
function get_newest_document_data($document)
{
    $new_document = RedisSocket::get('Socket_'.$document['id']);
    if (!empty($new_document)) {
        $new_document = json_decode($new_document, true);
        if (!empty($new_document['type'])) {
            if ($new_document['type'] == 'mindmap') {
                return json_decode($new_document['data'], true);
            } elseif (!empty($new_document['data']) && !empty($new_document['data']['html'])) {
                return $new_document['data'];
            } elseif ($new_document['type'] == 'ttable') {
                return $new_document['data'];
            } else {
                return $new_document['data'];
            }
        }
    }
    return json_decode($document['data'], true);
}

function cake_zero_condition_value($value)
{
    return is_numeric($value) && $value == 0 ? '0' : $value;
}

/**
 * 在报表模块开启限定表的读写分离
 * @param   $mvc_controller
 * @param   $mvc_action
 * @return
 */
function enable_read_write_split_for_report($mvc_controller, $mvc_action)
{
    $report_actions = [
        'stories::time_charts',
        'workspace_setting::member_statistical',
        'workspace_setting::member_statistical_done',
        'stories::stats_charts',
        'bugreports::stat_general',
        'bugreports::index_simple',
        'bugreports::stat_trend',
        'bugreports::stat',
        'bugreports::stat_other',
        'bugreports::stat_age',
        'reports::index',
        'reports::member_job_report',
        'reports::member_job_report_de',
        'reports::member_effort_detail',
    ];
    if (empty($mvc_controller) || empty($mvc_action)) {
        return;
    }
    $location = $mvc_controller . '::' . $mvc_action;
    if (!in_array($location, $report_actions)) {
        return;
    }
    $GLOBALS['SET_READ_WRITE_SPLITTING_MODEL'] = [
        'Baseline', 'Version', 'Feature', 'Module', 'Story', 'Iteration', 'Attachment', 'Release', 'Task', 'Bug', 'Tapd2Setting', 'Setting', 'LifeTime'
    ];
}

/**
 * 脚本开启限定表的读写分离
 * @return
 */
function enable_read_write_split_for_script($script_class)
{
    $allow_script = [
        'StatisticsGenerator',
        'TransitionLifeTimeGenerator'
    ];
    if (empty($script_name)) {
        return;
    }
    if (!in_array($script_class, $allow_script)) {
        return;
    }
    $GLOBALS['SET_READ_WRITE_SPLITTING_MODEL'] = [
        'Baseline', 'Version', 'Feature', 'Module', 'Story', 'Iteration', 'Attachment', 'Release', 'Task', 'Bug', 'Tapd2Setting', 'Setting', 'LifeTime'
    ];
}

function get_my_js_helper()
{
    static $my_js_helper;

    if (empty($my_js_helper)) {
        if (!class_exists('MyJavascriptHelper')) {
            uses('view' . DS . 'helper', 'class_registry');
            loadHelper('javascript');
            loadHelper('my_javascript');
        }
        $my_js_helper = new MyJavascriptHelper();
        $my_js_helper->loadConfig();
        $my_js_helper->themeWeb = '';
    }

    return $my_js_helper;
}

function js_link($js_src, $tag_options = array())
{
    $my_js_helper = get_my_js_helper();

    return $my_js_helper->link($js_src, null, null, false, false, false, $tag_options);
}

function js_block($js_code, $tag_options = array())
{
    $my_js_helper = get_my_js_helper();

    return $my_js_helper->codeBlock($js_code, true, $tag_options);
}

function valid_perpage($perpage)
{
    $perpage = intval($perpage);
    return in_array($perpage, array(10, 20, 50, 100));
}

function api_key_encrypt($data, $key, $iv, $method='aes-256-cbc')
{
    return openssl_encrypt($data, $method, $key, 0, $iv);
}

function api_key_decrypt($data, $key, $iv, $method='aes-256-cbc')
{
    return openssl_decrypt($data, $method, $key, 0, $iv);
}

function init_cloud_expire($version)
{
    if (BUSSINESS_ON_TRIAL) {
        if ($version == FREE_VERSION_TYPE) {
            $expire = date('Y-m-d', strtotime('+100 years'));
        } elseif ($version == LITE_VERSION_TYPE) {
            // $expire = date('Y-m-d', strtotime('+14 days')) . ' 23:59:59';
            $expire = date('Y-m-d', strtotime('+100 years'));
        } elseif ($version == PRO_VERSION_TYPE) {
            $expire = date('Y-m-d', strtotime('+14 days')) . ' 23:59:59';
        }
        return $expire;
    }
    return '';
}

function is_dir_empty($dir)
{
    if (!is_readable($dir)) {
        return null;
    }
    return (count(scandir($dir)) == 2);
}

function write_file_with_lock($full_file_name, $content, $timeout=1000)
{
    if ($fp = fopen($full_file_name, 'a+')) {
        $start_time = microtime();
        do {
            $can_write = flock($fp, LOCK_EX);
            if (!$can_write) {
                usleep(round(rand(0, 100)*100));
            }
        } while ((!$can_write) && ((microtime()-$start_time) < $timeout));
        if ($can_write) {
            fwrite($fp, $content);
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        return true;
    } else {
        return false;
    }
}
