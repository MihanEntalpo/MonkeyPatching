<?php

require_once("BaseGetterSetter.php");
require_once("StreamWrapper.php");

/**
 * Константа означающая PHP-токен "строка" (обычно это токены типа ",", ".", "{" и т.д.)
 */
if (!defined("U_STRING")) define("U_STRING",50000);

/**
 * Класс, предназначенный для патчинга файлов PHP и Smarty на лету
 * Smarty-шаблоны и HTML-код подменяются целиком, в PHP заменяются существующие и добавляются несуществующие методы
 */
class MonkeyPatching extends BaseGetterSetter
{

	/**
	 * Время кэширование структуры каталогов
	 * в секунах
	 */
	const CACHE_DIR_STRUCTURE = 200;

	/**
	 * Время кэширование информации о патчинге
	 * в секундах
	 */
	const CACHE_PATCH_INFO = 1200;

	/**
	 * Путь с базовыми файлами (которые нужно будет патчить на лету)
	 * @var string
	 */
	private $basePath=null;
	/**
	 * Путь к файлам с патчами - структура папок и имена файлов должны быть идентичны файлам в basePath
	 * @var string
	 */
	private $patchPath=null;

	/**
	 * Путь к папке с файлами кэша. Без неё работать ничего не будет.
	 * По умолчанию берется __DIR__ . "/cache"
	 * @var type
	 */
	private $cachePath=null;
	/**
	 * Период кэширования базовой структуры
	 * @var integer
	 */
	private $basePathCachePeriod = 600;
	/**
	 * Период кэширования структуры патчей
	 * @var integer
	 */
	private $patchPathCachePeriod = 20;

	/**
	 * Регулярные выражения соответствующие PHP-файлам
	 * @var array
	 */
	private $phpFilesRegexps = array("#.php$#");

	/**
	 * Регулярные выражения, соответствующие файлам SMARTY и другим которые надо подменять полностью
	 * @var array
	 */
	public $fullReplacePatterns = array('#.html$#');

	/**
	 * Массив файлов, которые нужно патчить, заполняется при запуске
	 * @var array
	 */
	private $filesToPatch = array();

	/**
	 * Массив файлов, которые были проверены на необходимость их патчить.
	 * Ключи - это имена файлов, а значения - true или false
	 * @var array
	 */
	public $filesExamined = array();

	/**
	 * Массив регулярных выражений, соответствующих путям, в которые при сканировании лезть не надо
	 * @var array
	 */
	public $excludePathsRegexps = array('#wa-data/protected/shop#','#wa-data/protected/shop#');

	/**
	 * Контекст выполнения.
	 * К нему можно обращаться в функциях - патчах, за пределами пропатченных функций равен null.
	 * Содержит массив данных о патченной функции, включая её новое имя.
	 * @var type
	 */
	protected static $context = null;

	/**
	 * Получить параметр из контекста, или весь контекст определённого класса и определённой функции
	 * @param string $className имя класса
	 * @param string $functionName имя функции
	 * @param string|null параметр (либо null чтобы вернулся весь массив целиком)
	 * @return array
	 */
	public static function getContext($className, $functionName, $param = null)
	{
		if (isset(self::$context[$className]))
		{
			$classParams = self::$context[$className];
			if (isset(self::$context[$className][$functionName]))
			{
				$funcParams = self::$context[$functionName];

				if (is_null($funcParams, $param))
				{
					return $funcParams;
				}
				else
				{
					return H::gissetAr($funcParams, $param);
				}
			}
			else
			{
				return null;
			}
		}
		else
		{
			return null;
		}
	}

	/**
	 * Задать контекст для класса или функции (или сбросить его на null)
	 * @param string $className класс
	 * @param string $functionName класс
	 * @param array $newContext новый контекст
	 */
	public static function setContext($className, $functionName, $newContext=null)
	{
		self::$context[$className][$functionName] = $newContext;
	}

	/**
	 * Набор геттеров и сеттеров
	 */
	public function setBasePath($newBasePath) { $this->basePath = $newBasePath; }
	public function setPatchPath($newPatchPath) { $this->patchPath = $newPatchPath; }
	public function getBasePath() { return $this->basePath; }
	public function getPatchPath() { return $this->patchPath;}

	/**
	 * Конструктор, вызываемый функцией досутпа к синглтону
	 * @param string $basePath базовый путь
	 * @param string $patchPath путь патчей
	 */
	private function __construct($basePath=null, $patchPath=null, $cachePath=null)
	{
		//Массив путей, которые надо задать из параметров функции, если параметры не указаны - задать "по умолчанию",
		//и проверить, есть ли они реально.
		$items = array(
			'basePath' => array('title'=>'Путь исходных файлов',
								'default' => __DIR__ . "/../../public_html"),
			'patchPath' => array('title'=>'Путь патченных файлов',
								'default' => __DIR__ . "/../../monkey_patch"),
			'cachePath' => array('title'=>'Путь кэша',
								'default' => __DIR__ . "/cache")
		);

		foreach($items as $name=>&$item)
		{
			//Считаем путь из переменной-параметра
			$item['path'] = ${$name};

			//Если значение нулевое - считаем из default'а
			if (is_null($item['path']))
			{
				$item['path'] = $item['default'];
			}

			//Если путь существует - возьмём от него realpath (а то там всякие /../../)
			if (file_exists($item['path']))
			{
				$item['path'] = realpath($item['path']);
			}
			else//Если путь не существует - кинем эксепшон
			{
				throw new Exception($item['title'] . " не существует: " . $item['path']);
			}

			//Запишем всё в поля класса
			$this->{$name} = $item['path'];

		}

		if (!is_writable($this->cachePath))
		{
			throw new Exception($items['cachePath']['title'] . " " . $this->cachePath . " не доступен для записи");
		}

	}

	/**
	 * Функция, проверяющая, нужно ли патчить указанный файл
	 * @param string $filename
	 * @return boolean
	 */
	public function isToBePatched($filename)
	{
		$result = (isset($this->filesToPatch[$filename]));
		$this->filesExamined[$filename] = $result;
		return $result;
	}

	/**
	 * Функция возвращает синглтон MonkeyPatching
	 * @param string $basePath путь к основным файлам
	 * @param string $patchPath путь к файлам-патчам
	 * @return \MonkeyPatching
	 */
	public static function I($basePath=null,$patchPath=null)
	{
		static $obj = null;
		if (is_null($obj))
		{
			$obj = new MonkeyPatching($basePath,$patchPath);
		}
		return $obj;
	}

	/**
	 * Получить значение из кэша или вычислить его через кложуру, если в кеше оно устарело
	 * @param string $name имя кэша
	 * @param mixed $variable ссылка на переменную, куда надо записать значение
	 * @param integer $period период кэширования
	 * @param CLOSURE $computeFunction кложура для вычисления значения
	 */
	public function getCacheOrCompute($name,&$variable,$period, $computeFunction)
	{
		if (!$this->getCache($name,$variable,$period))
		{
			$variable = $computeFunction();
			$this->setCache($name,$variable);
		}
	}

	/**
	 * Функция считывающая кэш
	 * @param string $name Название ключа кэша
	 * @param mixed $variable Ссылка на перемунню куда будет записан результат (Если он есть)
	 * @param integer $period Период устаревания кэша (в секундах)
	 * @return boolean Если удалось считать кэш, будет = true
	 */
	public function getCache($name,&$variable, $period=60)
	{
		$file = __DIR__ . "/cache/" . $name . ".cache";
		$result = false;
		if (file_exists($file))
		{
			$ctime = filectime($file);
			if (time() - $ctime <= $period)
			{
				$data = unserialize(file_get_contents($file));
				$variable = $data;
				$result = true;
			}
			else
			{
				unlink($file);
			}
		}
		return $result;
	}

	/**
	 * Функция сохраняет кэш
	 * @param string $name Ключ кэша
	 * @param mixed $value Значение кэша
	 */
	public function setCache($name,$value)
	{
		$file = __DIR__ . "/cache/" . $name . ".cache";
		if (file_exists($file))
		{
			unlink($file);
		}
		file_put_contents($file, serialize($value));
		chmod($file,0777);
	}

	/**
	 * Сканировать пути в поисках идентичных файлов
	 * @param string $basePath базовый путь
	 * @param type $patchPath
	 */
	public function scanPath()
	{
		$baseDirs = array();
		$patchDirs = array();
		$baseCacheName  = "baseDirs_"  . md5($this->basePath);
		$patchCacheName = "patchDirs_" . md5($this->patchPath);

		$patchFiles = $this->getCachedDirStructure($this->patchPath,"patchFiles_" . md5($this->patchPath), self::CACHE_DIR_STRUCTURE);

		$baseFiles = array();
		$basePath = $this->basePath;

		$this->getCacheOrCompute("baseFiles_" . md5($this->basePath), $baseFiles, self::CACHE_DIR_STRUCTURE, function() use($patchFiles, $basePath){
			$res = array();
			foreach($patchFiles as $file=>$info)
			{
				if (file_exists($basePath . "/" . $file))
				{
					$res[$file] = $info;
				}
			}
			return $res;
		});

		$filesToPatch = array();

		foreach($patchFiles as $file)
		{
			if (isset($baseFiles[$file]))
			{
				$filesToPatch[$file] = $file;
			}
		}

		return $filesToPatch;
	}

	/**
	 * Получить структуру дерикторий, с кэшированием
	 * @param string $path путь, который нужно просканировать
	 * @param string $cacheName название ключа кэша
	 * @param integer $cachePeriod период кэширования
	 * @return array массив файлов, с полными путями, за минусом пути, с которого нужно начать
	 */
	private function getCachedDirStructure($path,$cacheName,$cachePeriod)
	{

		$res = array();

		if (!$this->getCache($cacheName, $res, $cachePeriod))
		{
			$files = $this->scandir_recursive($path);

			foreach($files as $k=>$file)
			{
				$files[$k] = preg_replace("#^" . $path . "/#","",$file);
			}

			$res = array();

			foreach($files as $file)
			{
				$res[$file]=$file;
			}

			$this->setCache($cacheName, $res);

		}
		return $res;
	}

	/**
	 * Рекурсивно сканировать папку
	 * @param string $path путь
	 * @return array() массив файлов с полными путями
	 */
	private function scandir_recursive($path)
	{
		foreach($this->excludePathsRegexps as $regexp)
		{
			if (preg_match($regexp, $path)) return array();
		}

		$items = scandir($path);

		if (strpos($path,"..")!==false)
		{
			$x=$path;
		}
		$dirs = array();
		foreach($items as $k=>$item)
		{
			if (is_dir($path . "/" . $items[$k]))
			{
				if ($item!='.' && $item!='..')
				{
					$dirs[] = $path . "/" . $item;
				}
				unset($items[$k]);
			}
			else
			{
				$items[$k] = $path ."/". $item;
			}
		}

		foreach($dirs as $dir)
		{
			$subitems = $this->scandir_recursive($dir);
			foreach($subitems as $subitem)
			{
				$items[] = $subitem;
			}
		}
		return $items;
	}

	/**
	 * Включить процесс патчинга
	 * Производит сканирование структур каталогов, загрузки информации о патчинге
	 * и запуск враппера файловых операций
	 */
	public function go()
	{

		$filesToPatch = $this->scanPath();
		foreach($filesToPatch as $file)
		{
			$this->createPatchInfo($file);
		}

		StreamWrapper::wrap();
	}

	/**
	 * Получить имя патченного файла по имени базового файла
	 * @param string $baseFile базовый файл (полный путь)
	 * @return string патчевый файл (полный путь)
	 */
	public function getPatchFileFromBase($baseFile)
	{
		$file = preg_replace("#^" . $this->basePath . "/#","",$baseFile);
		return $this->patchPath . "/" . $file;
	}

	/**
	 * Является ли файл полностью заменяемым?
	 * @param string $file имя файла (ПОЛНЫЙ ПУТЬ!)
	 * @return boolean
	 */
	public function isFullReplacable($file)
	{
		$res = false;
		if (isset($this->filesToPatch[$file]))
		{
			$res = $this->filesToPatch[$file]['fullReplacable'];
		}
		else
		{
			foreach($this->fullReplacePatterns as $regexp)
			{

				if (preg_match($regexp,$file))
				{
					$res = true;
					break;
				}
			}
		}
		return $res;
	}

	/**
	 * Получить информацию о патче
	 * @param type $file
	 */
	private function createPatchInfo($file)
	{
		//echo "Creating patch info:" . $file . "<br>";
		$info=array();

		$baseFile = $this->basePath . "/" . $file;
		$patchFile = $this->patchPath . "/" . $file;

		$cacheKey = "info_" . str_replace("/","|", $this->basePath . "/" . $file);
		$patchedFile = __DIR__ . "/cache/patched_" . str_replace("/","|", $this->basePath . "/" . $file);
		$versionFile = __DIR__ . "/cache/version_" . str_replace("/","|", $this->basePath . "/" . $file);

		$fullReplacable = $this->isFullReplacable($this->basePath . "/" . $file);

		$cacheIsOk = false;

		$cacheIsOkButVersionsNeedUpdate = false;

		if ($this->getCache($cacheKey, $info, self::CACHE_PATCH_INFO))
		{
			//Если файл не полностью заменяем:
			if (!$fullReplacable)
			{
				if (file_exists($versionFile))
				{
					$versions_file = file_get_contents($versionFile);
					$versions = unserialize($versions_file);
					//Если есть информация о версии
					if ($versions)
					{
						//Считаем состояние файлов:
						$src_ok = (file_exists($baseFile) && !$this->isFileVersionInfoChanged($baseFile, $versions['src_version'], false, $cacheIsOkButVersionsNeedUpdate));

						//Если исходный файл поменялся - нужно проверить, поменялись ли в нём заменённые функции:
						if (!$src_ok)
						{
							$old_functions = $versions['replaced_functions'];
							$patchedFileInfo = $this->construct_patched_file_data(
								$this->parse_php(file_get_contents($baseFile)),
								$this->parse_php(file_get_contents($patchFile))
							);
							$new_functions = $patchedFileInfo['replaced_functions'];

							$changedFunctions = array();
							foreach($new_functions as $name=>$code)
							{
								if (!isset($old_functions[$name]) || $old_functions[$name] != $code)
								{
									$changedFunctions[] = $name;
								}
							}
							if ($changedFunctions)
							{
								die("Src file " . $file . " have changes in functions: <b>" . implode(",", $changedFunctions) . "</b>, please make sure, that your patch is still valid, and than remove old versions file");
							}
						}
						else
						{
							$patch_ok = (file_exists($patchFile) && !$this->isFileVersionInfoChanged($patchFile, $versions['patch_version'], false, $cacheIsOkButVersionsNeedUpdate));
							$patched_ok = (file_exists($patchedFile) && !$this->isFileVersionInfoChanged($patchedFile, $versions['patched_version'], false, $cacheIsOkButVersionsNeedUpdate));

							if ($patch_ok && $patched_ok && !$cacheIsOkButVersionsNeedUpdate)
							{
								$cacheIsOk = true;
							}
						}

					}
				}
			}
			else
			{
				if (file_exists($versionFile))
				{
					$versions_file = file_get_contents($versionFile);
					$versions = unserialize($versions_file);
					//Проверим состояние файла:
					$src_ok = (file_exists($baseFile) && !$this->isFileVersionInfoChanged($baseFile, $versions['src_version'], false, $cacheIsOkButVersionsNeedUpdate));

					//Если исходный файл поменялся - нужно проверить, поменялись ли в нём заменённые функции:
					if (!$src_ok)
					{
						die("Fully replaceable file $file have changed. Please make sure, that patch is still valid, and than remove old versions file.");
					}
					else
					{
						$patch_ok = (file_exists($patchFile) && !$this->isFileVersionInfoChanged($patchFile, $versions['patch_version'], false, $cacheIsOkButVersionsNeedUpdate));
						$patched_ok = (file_exists($patchedFile) && !$this->isFileVersionInfoChanged($patchedFile, $versions['patched_version'], false, $cacheIsOkButVersionsNeedUpdate));

						if ($patch_ok && $patched_ok && !$cacheIsOkButVersionsNeedUpdate)
						{
							$cacheIsOk = true;
						}
					}
				}
			}
		}

		//Если данные из кэша загрузить не удалось
		if (!$cacheIsOk)
		{
			//Если файл не полностью заменяем
			if (!$fullReplacable)
			{
				//Если файл частично заменяем - сконструируем пропатченую версию файла и сохраним её в нужном месте.
				$patchedFileInfo = $this->construct_patched_file_data(	$this->parse_php(file_get_contents($baseFile)),
																		$this->parse_php(file_get_contents($patchFile)));
				file_put_contents($patchedFile, $patchedFileInfo['file']);
				chmod($patchedFile, 0777);

				$replaced_functions = $patchedFileInfo['replaced_functions'];
			}
			else //Если файл полностью заменяем, запомним его путь
			{
				$patchedFile = $patchFile;
				$replaced_functions = array();
			}

			$info['patched_file'] = $patchedFile;
			$info['src_version'] = $this->getFileVersionInfo($baseFile);
			$info['patch_version'] = $this->getFileVersionInfo($patchFile);
			$info['patched_version'] = $this->getFileVersionInfo($patchedFile);
			$info['replaced_functions'] = $replaced_functions;


			file_put_contents($versionFile, serialize($info));
			chmod($versionFile, 0777);

			$this->setCache($cacheKey, $info);
		}

		if (is_array($info))
		{
			$this->filesToPatch[$this->basePath . "/" . $file] = array(	'file'=>$file,
																		'fullname'=>$this->basePath . "/" . $file,
																		'info'=>$info,
																		'fullReplacable'=>$fullReplacable);
		}

	}

	/**
	 * Провести проверку версий файла.
	 * Проверяется, совпадает ли размер файла и его дата изменения с теми, что переданы в массиве
	 * $versionInfo. Как правило данные этого массива должны быть получены для этого же файла, и
	 * закэшированы "на будущее". Если размер файла совпадает, но дата нет, будет проверена md5-сумма.
	 * Если размер файла не совпадает - то сумма проверяться не будет, сразу вернёт true.
	 * Если файл не существует - будет возвращено true
	 *
	 * @param string $filename имя файла
	 * @param array $versionInfo данные версии файла, обычно генерируются функцией getFileVersionInfo
	 * @param boolean $forceMd5 проверять md5-сумму файла независимо от того, изменилась дата и размер или нет
	 * @param boolean &$needUpdate ссылка на переменную, устанавливаемую в true, если при проверке какие-то
	 *							   параметры версии не совпадут, но, при этом, файл будет признан неизменным
	 *								(например если дата изменилась, а контент файла - нет)
	 * @return boolean возвращает true если файл изменился.
	 */
	public function isFileVersionInfoChanged($filename, $versionInfo, $forceMd5 = false, &$needUpdate = false)
	{
		$needUpdate = false;
		$result = true;
		//Если файл существует
		if (file_exists($filename))
		{
			//Если размер файла неизменен
			if (filesize($filename) == $versionInfo['size'])
			{
				//Если дата изменения файла неизменна
				if (filectime($filename) == $versionInfo['timestamp'])
				{
					//Если нет принуждения к сравнению md5, либо сравнение успешно
					if (!$forceMd5 || ($forceMd5 && (md5_file($filename) == $versionInfo['md5'])))
					{
						//значит изменений нет!
						$result = false;
					}
				}//Если дата изменения файла изменена
				else
				{
					//Но md5-сумма всё равно сходится
					if (md5_file($filename) == $versionInfo['md5'])
					{
						//Обновление нужно
						$needUpdate = true;
						//Нет изменений!
						$result = false;
					}
				}
			}

		}
		return $result;
	}

	/**
	 * Получить информацию о версии файла. Включает в себя размер, дату изменения и md5-сумму
	 * @param string $filename имя файла
	 * @return array('timestamp'=>Время изменения, 'size'=>Размер, 'md5'=>'md5-хэш')
	 */
	public function getFileVersionInfo($filename)
	{
		if (!file_exists($filename))
		{
			$res = array('timestamp'=>0, 'size'=>0, 'md5'=>"", 'filename'=>"");
		}
		else
		{
			$res = array(
				'timestamp' => filectime($filename),
				'size' => filesize($filename),
				'md5' => md5_file($filename),
				'filename' => $filename
			);
		}
		return $res;
	}

	public function getPatchedFilename($filename)
	{
		$patchInfo = $this->filesToPatch[$filename];
		return $patchInfo['info']['patched_file'];
	}



	/**
	 * Функция парсит PHP-файл.
	 * Используется для генерации информации о файле, используемой при патчинге
	 * @param string $file_content строка с загруженным контентом файла
	 * @return array() массив содержащий элементы:
	 *					'lexes'=> массив лексем файла, отличается от token_get_all однородной структурой элементов
	 *					'classes' => массив классов, содержащих в себе в том числе массив функций.
	 */
	function parse_php($file_content)
	{

		$debug = false;

		$lines = explode("\n", $file_content);

		$lexes = token_get_all($file_content);

		foreach($lexes as $lexKey=>$lex)
		{
			if (is_string($lex))
			{
				$lex = array(U_STRING, $lex, $curLine);
			}
			else
			{
				//$lex[0] = token_name($lex[0]);
				$curLine = $lex[2];
			}
			$lexes[$lexKey] = $lex;
		}

		$classes = array();
		$lastSpecifier = "public";

		$state = "Nowhere";
		$nesting = 0;

		$curLine = 0;

		$pubPrivProt = array('name'=>null,'lexPos'=>null);

		foreach($lexes as $lexPos=>$lex)
		{

			$lexType = $lex[0];

			if ($debug)
			{
				echo "\nState:" . $state . ", nesting={$nesting}\n\n";

				echo $lex[2] . ": " . str_replace("\n",'\n',$lex[1]) . "					(" . token_name($lex[0]) . ")\n";
				echo "LexType:" . $lexType . "\n";
			}

			if ($state == "Nowhere")
			{
				if ($lexType == T_CLASS)
				{
					$state = "Class";
					$curClass = array('name'=>null, 'nesting'=>null, 'functions'=>array());
				}
			}
			else if($state == "Class")
			{
				if ($lexType == T_STRING)
				{
					$curClass['name'] = $lex[1];
					$curClass['nesting'] = $nesting + 1;
					$curClass['startLexPos'] = $lexPos;
					$state = "NamedClass";
				}
			}
			else if($state == "NamedClass")
			{
				if ($lexType == U_STRING)
				{
					if ($lex[1] == "{")
					{
						$nesting +=1;
					}
					else if($lex[1] == "}")
					{
						$nesting -=1;
						if ($nesting < $curClass['nesting'])
						{
							$curClass['endLexPos'] = $lexPos;
							$classes[$curClass['name']] = $curClass;
							$state = "Nowhere";
						}
					}

				}
				else if(in_array($lexType, array(T_PUBLIC,T_PRIVATE,T_PROTECTED)))
				{
					$pubPrivProt = array("name"=>$lex[1], "lexPos" => $lexPos);
				}
				else if($lexType == T_VARIABLE)
				{
					$pubPrivProt = array("name"=>null,"lexPos"=>null);
				}
				else if ($lexType == T_FUNCTION)
				{
					$curFunction = array('name'=>null, 'nesting' => $nesting + 1, 'access' => 'public', 'startLexPos' => $lexPos);
					$state = "Function";
				}
			}
			else if($state == "Function")
			{
				if ($lexType == T_STRING)
				{
					$state = "NamedFunction";
					$curlyOpenMode = false;
					$curFunction['name'] = $lex[1];
					if ($pubPrivProt['name'])
					{
						$curFunction['access'] = $pubPrivProt['name'];
						$curFunction['startLexPos'] = $pubPrivProt['lexPos'];
					}
				}
			}
			else if ($state == "NamedFunction")
			{
				if ($lexType == T_CURLY_OPEN)
				{
					$curlyOpenMode = true;
				}
				else if ($lexType == U_STRING)
				{
					if ($lex[1] == "{")
					{
						$nesting +=1;
					}
					else if($lex[1] == "}")
					{
						if ($curlyOpenMode)
						{
							$curlyOpenMode = false;
						}
						else
						{
							$nesting -=1;
							if ($nesting < $curFunction['nesting'])
							{
								$curFunction['endLexPos'] = $lexPos;
								$curClass['functions'][$curFunction['name']] = $curFunction;
								$state = "NamedClass";
							}
						}
					}
				}
			}
		}

		foreach($classes as $classNum => $class)
		{
			foreach($class['functions'] as $funcNum => $func)
			{
				$code = "";
				for($i = $func['startLexPos']; $i < $func['endLexPos']+1; $i++)
				{
					$code .= $lexes[$i][1];
				}
				$classes[$classNum]['functions'][$funcNum]['code'] = $code;
			}
		}

		return array('lexes'=>$lexes, 'classes' => $classes);

	}


	/**
	 * Сформировать имя для старой функции
	 * @param type $className
	 * @param type $functionName
	 * @return type
	 */
	public static function get_old_function_name($className, $functionName)
	{
		return "old_" . $className . "_" . $functionName . "_mp";
	}



	/**
	 * Сконструировать данные для патчинга файла
	 * @param array $srcParseData массив результатов анализа исходного файла (возвращается parse_php)
	 * @param array $patchParseData массив результатов анализа конечного файла (возвращается parse_php)
	 * @return array массив данных пропатченного файла, а именно:
	 *			'file' => содержит полный текст пропатченного файла
	 *			'replaced_functions' => содержит массив оригинальных функций, которые были заменены
	 *			'lexes' => массив лексем готового пропатченного файла
	 */
	protected function construct_patched_file_data($srcParseData, $patchParseData)
	{

		$recomputeLexemPositions = function($pos_old, $pos_new, &$data) {

			foreach($data['classes'] as $className => &$classInfo)
			{
				foreach(array('startLexPos', 'endLexPos') as $lexKey)
				{
					if ($classInfo[$lexKey] >= $pos_old)
					{
						$classInfo[$lexKey] += $pos_new - $pos_old;
					}
				}

				foreach($classInfo['functions'] as $funcName => &$funcInfo)
				{
					foreach(array('startLexPos', 'endLexPos') as $lexKey)
					{
						if ($funcInfo[$lexKey] >= $pos_old)
						{
							$funcInfo[$lexKey] += $pos_new - $pos_old;
						}
					}
				}
			}
		};

		$result = array(
						'replaced_functions' => array(),
						'file' => "",
						'lexes' => $srcParseData['lexes'],
		);

		$patchLexes = $patchParseData['lexes'];

		$srcLexes = $srcParseData['lexes'];

		$patchedLexes = $srcParseData['lexes'];

		//Обратим классы исходного файла задом наперед
		$reversedClasses = array_reverse($srcParseData['classes']);

		foreach($reversedClasses as $className=>$srcClassData)
		{
			if (isset($srcParseData['classes'][$className]))
			{
				$classData = $srcParseData['classes'][$className];

				foreach($patchParseData['classes'][$className]['functions'] as $funcName=>$funcData)
				{
					if (!isset($srcParseData['classes'][$className]['functions'][$funcName]))
					{

						$patchFuncInfo = $patchParseData['classes'][$className]['functions'][$funcName];


						$result['lexes'] = $this->array_replace_partly(
												$patchParseData['lexes'], $result['lexes'],
												$funcData['startLexPos'], $funcData['endLexPos'],
												$srcParseData['classes'][$className]['endLexPos'],
												$srcParseData['classes'][$className]['endLexPos'] - 1);




					}
				}

				//Получим функции исходного класса в обратной последовательности
				$reversedFunctions = array_reverse($srcParseData['classes'][$className]['functions']);

				foreach($reversedFunctions as $funcName=>$srcFuncData)
				{
					if (isset($patchParseData['classes'][$className]['functions'][$funcName]))
					{

						$srcFuncInfo = $srcParseData['classes'][$className]['functions'][$funcName];
						$patchFuncInfo = $patchParseData['classes'][$className]['functions'][$funcName];

						$summaryLexes = array();

						for ($i = $patchFuncInfo['startLexPos']; $i <= $patchFuncInfo['endLexPos']; $i++)
						{
							$lex = $patchParseData['lexes'][$i];
							$summaryLexes[] = $lex;
						}

						$summaryLexes[] = array(U_STRING, "\n", $summaryLexes[count($summaryLexes)-1][2]);

						for($i = $srcFuncInfo['startLexPos']; $i<=$srcFuncInfo['endLexPos']; $i++)
						{
							$lex = $srcParseData['lexes'][$i];
							if ($lex[0] == T_STRING && $lex[1] == $funcName)
							{
								$lex[1] = self::get_old_function_name($className, $funcName);
							}
							$summaryLexes[] = $lex;

						}


						$funcData = $patchParseData['classes'][$className]['functions'][$funcName];
						$result['replaced_functions'][$funcName] = $srcParseData['classes'][$className]['functions'][$funcName]['code'];

//						$result['lexes'] = $this->array_replace_partly(
//												$patchParseData['lexes'], $result['lexes'],
//												$funcData['startLexPos'], $funcData['endLexPos'],
//												$srcFuncData['startLexPos'], $srcFuncData['endLexPos']
//											);

						$result['lexes'] = $this->array_replace_partly(
												$summaryLexes, $result['lexes'],
												0, count($summaryLexes)-1,
												$srcFuncData['startLexPos'], $srcFuncData['endLexPos']
											);


					}
				}
			}
		}

		$result['file'] = $this->lexes_to_string($result['lexes']);

		return $result;

	}

	/**
	* Замена элементов из одного массива элементами из другого
	* @param array $src_array исходный массив (откуда копировать элементы)
	* @param array $dest_array конечный массив (куда вставлять элементы)
	* @param integer $src_from откуда начинать копировать элементы
	* @param integer $src_to где заканчивать копировать элементы
	* @param integer $dest_from откуда начинаются элементы которые надо заменить
	* @param integer $dest_to где заканчиваются элементы которые надо заменить
	* @return array() результат
	*/
	protected function array_replace_partly($src_array, $dest_array, $src_from, $src_to, $dest_from, $dest_to)
	{
		$res = array();

		$r = range(0, $dest_from-1);
		foreach($r as $i)
		{
			$res[] = $dest_array[$i];
		}

		$r = range($src_from, $src_to);
		foreach($r as $i)
		{
			$res[] = $src_array[$i];
		}

		$r = range($dest_to+1,count($dest_array)-1);
		foreach($r as $i)
		{
			$res[] = $dest_array[$i];
		}

		return $res;
	}

	/**
	 * Сформировать текст из лексем (лексемы доработанные, аналогичные тем, что выдаёт parse_php(...)['lexes']
	 * @param array $lexes массив лексем
	 * @return string
	 */
	protected function lexes_to_string($lexes)
	{
		$res = array();
		foreach($lexes as $lex)
		{
			$res[] = $lex[1];
		}
		return implode("",$res);
	}



}

