<?php
/**
* PHP日志类
* 基于lzyy的plog修改:https://github.com/lzyy/plog
* 作者:brainmix
* 1.合并成单文件版,某些场景使用方便.
* 2.增加单文件日志
* 使用方法:
* 1.修改PlogConfig中的getConfig返回数组,根据需要配置.
* 2.需要使用的地方加入如下代码:
   require 'ploglite.php';
   $log = Plog::factory(__FILE__);
   $log->debug('heal the world');
*/


/**
* 日志配置类
*/
class PlogConfig{
    static public function getConfig(){
        return array(
            'loggers' => array(
                //'base' => dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR.'demo'.DIRECTORY_SEPARATOR,
                'base' => dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR,
                'system' => 'system',
                'app' => 'app'
            ),
            'levels' => array('DEBUG', 'INFO', 'ERROR', 'WARN', 'FATAL'),
            'handlers' => array(
                'file' => array(
                    'driver' => 'file',
                    'level' => array('DEBUG'),
                    'formatter' => 'generic',
                    'enabled' => false, //是否允许
                    'config' => array(
                    'floderPrefix' =>'', //文件夹前缀
                    'dir' => dirname(__FILE__),
                    ),
                ),
                'singleFile' => array(
                    'driver' => 'singlefile',
                    'level' => array('DEBUG'),
                    'formatter' => 'generic',
                    'enabled' => true,
                    'config' => array(
                    'filename' =>'log-test', //单日志文件文件名
                    'dir' => dirname(__FILE__),
                    ),
                ),
            ),
            'formatters' => array(
                'generic' => '{time} {level} [{logger}] {uri} """{message}"""',
            ),
        );
    }
}

class Plog_Exception extends Exception {}

class Plog 
{
	private static $_instances = array();
	private static $_config = array();

	public static function set_config($config)
	{
		self::$_config = $config;
	}

	public static function get_config()
	{
        //MOD:brainmix 自动读取PlogConfig类中的配置,为把pLog精简为单文件版
        if (empty(self::$_config)) 
            self::set_config(PlogConfig::getConfig());
		return self::$_config;
	}

	public static function factory($filepath)
	{
		static $include_path;

		if(empty($include_path))
		{
			set_include_path(get_include_path().PATH_SEPARATOR.dirname(__FILE__));
			$include_path = true;
		}

		$config = self::get_config();
		$dest_logger = $filepath;
		$base_path = $config['loggers']['base'];
		unset($config['loggers']['base']);
		foreach ($config['loggers'] as $logger => $path)
		{
			if (substr($filepath, 0, strlen($base_path.$path)) == $base_path.$path)
			{
				$filepath = substr(str_replace($base_path.$path.DIRECTORY_SEPARATOR, '', $filepath), 0, -4);
				$dest_logger = $logger.'.'.str_replace(DIRECTORY_SEPARATOR, '.', $filepath);
			}
		}

		if (empty(self::$_instances[$dest_logger]))
		{
			self::$_instances[$dest_logger] = new self($dest_logger);
		}

		return self::$_instances[$dest_logger];
	}

	private $_logger;
	private $_logger_handlers = array();

	public function __construct($logger)
	{
		$this->_logger = $logger;
		$config = self::get_config();
		foreach ($config['handlers'] as $handler)
		{
			if (!empty($handler['enabled']) && $handler['enabled'] == true)
			{
				$this->_logger_handlers[] = $handler;
			}
		}
	}

	public function __call($method, $args)
	{
		$config = self::get_config();
		$method = strtoupper($method);
		if (!in_array($method, $config['levels']))
		{
			throw Plog_Exception(sprintf('method not allowed: %s', $method));
		}
		foreach ($this->_logger_handlers as $handler)
		{
			if (in_array($method, $handler['level']))
			{
				$class = 'Plog_Handler_'.strtoupper($handler['driver']);
				if (!class_exists($class))
					require strtolower(str_replace('_', DIRECTORY_SEPARATOR, $class)).'.php';
				$class = $class::instance($handler['driver']);
				$class->set_formatter_args(array(
					'message' => $args[0],
					'level' => $method,
					'logger' => $this->_logger,
				));
				$class->save();
			}
		}
	}
}



/**
* 日志格式化输出类
*/
class Plog_Formatter 
{
	public $args;

	public function getTime()
	{
		static $time;
		empty($time) && $time = date('Y/m/d H:i:s');
		return $time;
	}

	public function getUri()
	{
		$clientIp = '0.0.0.0';
		if (isset($_SERVER['CLIENT_IP']))
		{
			$clientIp = $_SERVER['CLIENT_IP'];
		}
		elseif (isset($_SERVER['X_FORWARDED_FOR']))
		{
			$clientIp = $_SERVER['X_FORWARDED_FOR'];
		}
		elseif (isset($_SERVER['REMOTE_ADDR']))
		{
			$clientIp = $_SERVER['REMOTE_ADDR'];
		}

		return $clientIp;
	}

	public function getIp()
	{
		return $_SERVER['REMOTE_ADDR'];
	}

	public function getLevel()
	{
		return $this->args['level'];
	}

	public function getLogger()
	{
		return $this->args['logger'];
	}

	public function getMessage()
	{
		return $this->args['message'];
	}
}
/**
*日志处理抽象类
**/
abstract class Plog_Handler_Abstract
{
	protected static $_instances = array();

	public static function instance($driver)
	{
		if (empty(self::$_instances[$driver]))
		{
			$class = 'Plog_Handler_'.$driver;
			self::$_instances[$driver] = new $class($driver);
		}

		return self::$_instances[$driver];
	}

	protected $_formatter;
	protected $_local_config = array();
	protected $_plog_formatter;

	public function __construct($driver)
	{
		$config = Plog::get_config();
		$class = get_class($this);
		foreach ($config['handlers'] as $handler)
		{
			if (strtolower($handler['driver']) == $driver)
			{
				$this->_formatter = $config['formatters'][$handler['formatter']];
				$this->_local_config = $handler['config'];
			}
		}
		$this->_plog_formatter = new Plog_Formatter();
	}

	public function set_formatter_args($args)
	{
		$this->_plog_formatter->args = $args;
	}

	protected function _format()
	{
		preg_match_all('/\{([a-zA-Z_-]+)\}/u', $this->_formatter, $matches);
		$replace_arr = array();
		foreach($matches[1] as $key)
		{
			$replace_arr['{'.$key.'}'] = $this->_plog_formatter->{'get'.$key}();
		}
		return strtr($this->_formatter, $replace_arr);
	}

	abstract function save();
}



/**
*日志处理类(文件)
*/
class Plog_Handler_File extends Plog_Handler_Abstract
{
	public function save()
	{
		$log_message = $this->_format().PHP_EOL;
		$dir = rtrim($this->_local_config['dir'], '/');
		$dest_dir = $dir.'/'.$this->_local_config['floderPrefix'].date('Y').'/'.date('m');
		if(!is_dir($dest_dir))
		{
			mkdir($dest_dir, 0777, true);
		}
		$dest_file = $dest_dir.'/'.date('Y-m-d').'.log';
		touch($dest_dir);
		chmod($dest_dir, 0777);
		file_put_contents($dest_file, $log_message, FILE_APPEND);
	}
}
/**
*日志处理类(单文件)
*/
class Plog_Handler_SingleFile extends Plog_Handler_Abstract
{
	public function save()
	{
		$log_message = $this->_format().PHP_EOL;
        var_dump($this->_local_config);
		$dir = rtrim($this->_local_config['dir'], '/');
		$dest_dir = $dir.'/';
		if(!is_dir($dest_dir))
		{
			mkdir($dest_dir, 0777, true);
		}
		$dest_file = $dest_dir.'/'.$this->_local_config['filename'].'.log';
		touch($dest_dir);
		chmod($dest_dir, 0777);
		file_put_contents($dest_file, $log_message, FILE_APPEND);
	}
}
