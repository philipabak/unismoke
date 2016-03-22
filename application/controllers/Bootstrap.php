<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Bootstrap extends CI_Controller {

	public function init()
	{
		$this->load->helper('url');
		if(!file_exists(APPPATH.'/config/database.php'))
		{
			redirect('install');
			exit;
		}

		session_start();

		//set the session userdata if non-existant
		if(!isset($_SESSION['userdata']))
		{
			$_SESSION['userdata'] = [];
		}
		//set newFlashdata if non-existent
		if(!isset($_SESSION['newFlashdata']))
		{
			$_SESSION['newFlashdata'] = [];
		}

		//empty out the "oldFlashdata" field
		$_SESSION['oldFlashdata'] = [];

		//shift newFlashdata over to oldFlashdata
		$_SESSION['oldFlashdata'] = $_SESSION['newFlashdata'];
		$_SESSION['newFlashdata'] = [];

		//module list
		$GLOBALS['modules'] = [];

		if(!file_exists(APPPATH.'config/manifest.php'))
		{
			$this->load->helper('file');

			$manifest = "<?php defined('BASEPATH') OR exit('No direct script access allowed');\n//DO NOT EDIT THIS FILE\n\n";

			$this->classMap = [];
			$paths = [FCPATH.'addons', APPPATH.'modules', APPPATH.'libraries', APPPATH.'core'];

			$paymentModules = [];
			$shippingModules = [];
			$themeShortcodes = [];
			$routes = [];
			$modules = [];

			//just modules
			$moduleDirectories = [
				APPPATH.'modules',
				FCPATH.'addons'
			];

			foreach($moduleDirectories as $moduleDirectory)
			{
				foreach(array_diff( scandir($moduleDirectory), ['..', '.']) as $availableModule)
				{
					if(is_dir($moduleDirectory.'/'.$availableModule))
					{
						//create a codeigniter package path to the module.
						//$this->load->add_package_path($moduleDirectory.'/'.$availableModule);
						$modules[] = $moduleDirectory.'/'.$availableModule;
					}
				}
			}

			foreach($paths as $path)
			{
				$dir_iterator = new RecursiveDirectoryIterator($path);
				$iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);

				foreach ($iterator as $file) {

					if(!is_dir($file))
					{
						$ext = pathinfo($file,PATHINFO_EXTENSION);
						$filename = pathinfo($file, PATHINFO_FILENAME);
						if($ext == 'php')
						{
							if($filename == 'manifest')
							{
								include($file);
							}
							$this->getPhpClasses((string)$file);
						}
					}
				}
			}

			$manifest .= '//ClassMap for autoloader'."\n".'$classes = '.var_export($this->classMap, true).';';
			$manifest .= "\n\n".'//Available Payment Modules'."\n".'$GLOBALS[\'paymentModules\'] ='.var_export($paymentModules, true).';';
			$manifest .= "\n\n".'//Available Shipping Modules'."\n".'$GLOBALS[\'shippingModules\'] = '.var_export($shippingModules, true).';';
			$manifest .= "\n\n".'//Theme Shortcodes'."\n".'$GLOBALS[\'themeShortcodes\'] = '.var_export($themeShortcodes, true).';';
			$manifest .= "\n\n".'//Complete Module List'."\n".'$GLOBALS[\'modules\'] = '.var_export($modules,true).';';
			$manifest .= "\n\n".'//Defined Routes'."\n".'$routes = '.var_export($routes,true).';';
			
			//generate the autoload file
			write_file(APPPATH.'config/manifest.php', $manifest);
		}

		require(APPPATH.'config/manifest.php');

		//load in the database.
		$this->load->database();

		//set up routing...
		$router = new \AltoRouter();

		if(isset($_SERVER['BASE'])) {
			$base = trim($_SERVER['BASE'], '/');
			if ($base != '') {
				$router->setBasePath('/' . $base);
			}
		}

		//set the homepage route
		$router->map('GET|POST', '/', 'GoCart\Controller\Page#homepage');
		
		//map the routes from the manifest.
		foreach($routes as $route) {
			$router->map($route[0], $route[1], $route[2]);
		}

		foreach($GLOBALS['modules'] as $module)
		{
			$this->load->add_package_path($module);
		}

		//autoloader for Modules
		spl_autoload_register(function($class) use ($classes){

			if(isset($classes[$class]))
			{
				include($classes[$class]);
			}
		});

		//autoload some libraries here.
		$this->load->model('Settings');
		$this->load->library(['session', 'auth', 'form_validation']);
		$this->load->helper(['file', 'string', 'html', 'language', 'form', 'formatting']);

		//get settings from the DB
		$settings = $this->Settings->get_settings('gocart');

		//loop through the settings and set them in the config library
		foreach($settings as $key=>$setting)
		{
			//special for the order status settings
			if($key == 'order_statuses')
			{
				$setting = json_decode($setting, true);
			}

			//other config items get set directly to the config class
			$this->config->set_item($key, $setting);
		}

		date_default_timezone_set(config_item('timezone'));

		//if SSL is enabled in config force it here.
		if (config_item('ssl_support') && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == 'off'))
		{
			$this->config->set_item('base_url', str_replace('http://', 'https://', config_item('base_url') ));
			redirect( \CI::uri()->uri_string() );
		}

		//do we have a dev username & password in place?
		//if there is a username and password for dev, require them
		if(config_item('stage_username') != '' && config_item('stage_password') != '')
		{
			if (!isset($_SERVER['PHP_AUTH_USER'])) {
				header('WWW-Authenticate: Basic realm="Login to restricted area"');
				header('HTTP/1.0 401 Unauthorized');
				echo config_item('company_name').' Restricted Location';
				exit;
			} else {
				if(config_item('stage_username') != $_SERVER['PHP_AUTH_USER'] || config_item('stage_password') != $_SERVER['PHP_AUTH_PW'])
				{
					header('WWW-Authenticate: Basic realm="Login to restricted area"');
					header('HTTP/1.0 401 Unauthorized');
					echo 'Restricted Location';
					exit;
				}
			}
		}

		// lets run the routes
		$match = $router->match();

		// call a closure
		if( $match && is_callable( $match['target'] ) ) {
			call_user_func_array( $match['target'], $match['params'] );
		}

		// parse a string and call it
		elseif($match && is_string($match['target']))
		{
			$target = explode('#', $match['target']);

			try {
				$class = new $target[0];
				call_user_func_array([$class, $target[1]], $match['params']);

			} catch(Exception $e) {
				var_dump($e);
				throw_404();
			}
		}

		// throw a 404 error
		else
		{
			throw_404();
		}
	}

	private function getPhpClasses($file) {

		$phpcode = file_get_contents($file);

		$namespace = 0;
		$tokens = token_get_all($phpcode);
		$count = count($tokens);
		$dlm = false;
		for ($i = 2; $i < $count; $i++)
		{
			if ((isset($tokens[$i - 2][1]) && ($tokens[$i - 2][1] == "phpnamespace" || $tokens[$i - 2][1] == "namespace")) || ($dlm && $tokens[$i - 1][0] == T_NS_SEPARATOR && $tokens[$i][0] == T_STRING))
			{
				if (!$dlm)
				{
					$namespace = 0; 
				}
				if (isset($tokens[$i][1]))
				{
					$namespace = $namespace ? $namespace . "\\" . $tokens[$i][1] : $tokens[$i][1];
					$dlm = true; 
				}
			}
			elseif ($dlm && ($tokens[$i][0] != T_NS_SEPARATOR) && ($tokens[$i][0] != T_STRING))
			{
				$dlm = false; 
			} 
			if (($tokens[$i - 2][0] == T_CLASS || (isset($tokens[$i - 2][1]) && $tokens[$i - 2][1] == "phpclass")) && $tokens[$i - 1][0] == T_WHITESPACE && $tokens[$i][0] == T_STRING)
			{
				$class_name = $tokens[$i][1]; 
				
				if($namespace != '')
				{
					$this->classMap[$namespace.'\\'.$class_name] = $file;
				}
				else
				{
					$this->classMap[$class_name] = $file;
				}
			}
		} 
	}
}