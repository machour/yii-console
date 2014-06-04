<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\console\controllers;

use Yii;
use yii\console\Exception;
use yii\console\Controller;
use yii\helpers\VarDumper;

/**
 * Allows you to combine and compress your JavaScript and CSS files.
 *
 * Usage:
 * 1. Create a configuration file using 'template' action:
 *    yii asset/template /path/to/myapp/config.php
 * 2. Edit the created config file, adjusting it for your web application needs.
 * 3. Run the 'compress' action, using created config:
 *    yii asset /path/to/myapp/config.php /path/to/myapp/config/assets_compressed.php
 * 4. Adjust your web application config to use compressed assets.
 *
 * Note: in the console environment some path aliases like '@webroot' and '@web' may not exist,
 * so corresponding paths inside the configuration should be specified directly.
 *
 * Note: by default this command relies on an external tools to perform actual files compression,
 * check [[jsCompressor]] and [[cssCompressor]] for more details.
 *
 * @property \yii\web\AssetManager $assetManager Asset manager instance. Note that the type of this property
 * differs in getter and setter. See [[getAssetManager()]] and [[setAssetManager()]] for details.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class AssetController extends Controller
{
    /**
     * @var string controller default action ID.
     */
    public $defaultAction = 'compress';
    /**
     * @var array list of asset bundles to be compressed.
     */
    public $bundles = [];
    /**
     * @var array list of asset bundles, which represents output compressed files.
     * You can specify the name of the output compressed file using 'css' and 'js' keys:
     * For example:
     *
     * ~~~
     * 'app\config\AllAsset' => [
     *     'js' => 'js/all-{hash}.js',
     *     'css' => 'css/all-{hash}.css',
     *     'depends' => [ ... ],
     * ]
     * ~~~
     *
     * File names can contain placeholder "{hash}", which will be filled by the hash of the resulting file.
     */
    public $targets = [];
    /**
     * @var string|callable JavaScript file compressor.
     * If a string, it is treated as shell command template, which should contain
     * placeholders {from} - source file name - and {to} - output file name.
     * Otherwise, it is treated as PHP callback, which should perform the compression.
     *
     * Default value relies on usage of "Closure Compiler"
     * @see https://developers.google.com/closure/compiler/
     */
    public $jsCompressor = 'java -jar compiler.jar --js {from} --js_output_file {to}';
    /**
     * @var string|callable CSS file compressor.
     * If a string, it is treated as shell command template, which should contain
     * placeholders {from} - source file name - and {to} - output file name.
     * Otherwise, it is treated as PHP callback, which should perform the compression.
     *
     * Default value relies on usage of "YUI Compressor"
     * @see https://github.com/yui/yuicompressor/
     */
    public $cssCompressor = 'java -jar yuicompressor.jar --type css {from} -o {to}';

    /**
     * @var array|\yii\web\AssetManager [[\yii\web\AssetManager]] instance or its array configuration, which will be used
     * for assets processing.
     */
    private $_assetManager = [];

    /**
     * Returns the asset manager instance.
     * @throws \yii\console\Exception on invalid configuration.
     * @return \yii\web\AssetManager asset manager instance.
     */
    public function getAssetManager()
    {
        if (!is_object($this->_assetManager)) {
            $options = $this->_assetManager;
            if (!isset($options['class'])) {
                $options['class'] = 'yii\\web\\AssetManager';
            }
            if (!isset($options['basePath'])) {
                throw new Exception("Please specify 'basePath' for the 'assetManager' option.");
            }
            if (!isset($options['baseUrl'])) {
                throw new Exception("Please specify 'baseUrl' for the 'assetManager' option.");
            }
            $this->_assetManager = Yii::createObject($options);
        }

        return $this->_assetManager;
    }

    /**
     * Sets asset manager instance or configuration.
     * @param \yii\web\AssetManager|array $assetManager asset manager instance or its array configuration.
     * @throws \yii\console\Exception on invalid argument type.
     */
    public function setAssetManager($assetManager)
    {
        if (is_scalar($assetManager)) {
            throw new Exception('"' . get_class($this) . '::assetManager" should be either object or array - "' . gettype($assetManager) . '" given.');
        }
        $this->_assetManager = $assetManager;
    }

    /**
     * Combines and compresses the asset files according to the given configuration.
     * During the process new asset bundle configuration file will be created.
     * You should replace your original asset bundle configuration with this file in order to use compressed files.
     * @param string $configFile configuration file name.
     * @param string $bundleFile output asset bundles configuration file name.
     */
    public function actionCompress($configFile, $bundleFile)
    {
        $this->loadConfiguration($configFile);
        $bundles = $this->loadBundles($this->bundles);
        $targets = $this->loadTargets($this->targets, $bundles);
        foreach ($targets as $name => $target) {
            echo "Creating output bundle '{$name}':\n";
            if (!empty($target->js)) {
                $this->buildTarget($target, 'js', $bundles);
            }
            if (!empty($target->css)) {
                $this->buildTarget($target, 'css', $bundles);
            }
            echo "\n";
        }

        $targets = $this->adjustDependency($targets, $bundles);
        $this->saveTargets($targets, $bundleFile);
    }

    /**
     * Applies configuration from the given file to self instance.
     * @param string $configFile configuration file name.
     * @throws \yii\console\Exception on failure.
     */
    protected function loadConfiguration($configFile)
    {
        echo "Loading configuration from '{$configFile}'...\n";
        foreach (require($configFile) as $name => $value) {
            if (property_exists($this, $name) || $this->canSetProperty($name)) {
                $this->$name = $value;
            } else {
                throw new Exception("Unknown configuration option: $name");
            }
        }

        $this->getAssetManager(); // check if asset manager configuration is correct
    }

    /**
     * Creates full list of source asset bundles.
     * @param string[] $bundles list of asset bundle names
     * @return \yii\web\AssetBundle[] list of source asset bundles.
     */
    protected function loadBundles($bundles)
    {
        echo "Collecting source bundles information...\n";

        $am = $this->getAssetManager();
        $result = [];
        foreach ($bundles as $name) {
            $result[$name] = $am->getBundle($name);
        }
        foreach ($result as $bundle) {
            $this->loadDependency($bundle, $result);
        }

        return $result;
    }

    /**
     * Loads asset bundle dependencies recursively.
     * @param \yii\web\AssetBundle $bundle bundle instance
     * @param array $result already loaded bundles list.
     * @throws Exception on failure.
     */
    protected function loadDependency($bundle, &$result)
    {
        $am = $this->getAssetManager();
        foreach ($bundle->depends as $name) {
            if (!isset($result[$name])) {
                $dependencyBundle = $am->getBundle($name);
                $result[$name] = false;
                $this->loadDependency($dependencyBundle, $result);
                $result[$name] = $dependencyBundle;
            } elseif ($result[$name] === false) {
                throw new Exception("A circular dependency is detected for bundle '$name'.");
            }
        }
    }

    /**
     * Creates full list of output asset bundles.
     * @param array $targets output asset bundles configuration.
     * @param \yii\web\AssetBundle[] $bundles list of source asset bundles.
     * @return \yii\web\AssetBundle[] list of output asset bundles.
     * @throws Exception on failure.
     */
    protected function loadTargets($targets, $bundles)
    {
        // build the dependency order of bundles
        $registered = [];
        foreach ($bundles as $name => $bundle) {
            $this->registerBundle($bundles, $name, $registered);
        }
        $bundleOrders = array_combine(array_keys($registered), range(0, count($bundles) - 1));

        // fill up the target which has empty 'depends'.
        $referenced = [];
        foreach ($targets as $name => $target) {
            if (empty($target['depends'])) {
                if (!isset($all)) {
                    $all = $name;
                } else {
                    throw new Exception("Only one target can have empty 'depends' option. Found two now: $all, $name");
                }
            } else {
                foreach ($target['depends'] as $bundle) {
                    if (!isset($referenced[$bundle])) {
                        $referenced[$bundle] = $name;
                    } else {
                        throw new Exception("Target '{$referenced[$bundle]}' and '$name' cannot contain the bundle '$bundle' at the same time.");
                    }
                }
            }
        }
        if (isset($all)) {
            $targets[$all]['depends'] = array_diff(array_keys($registered), array_keys($referenced));
        }

        // adjust the 'depends' order for each target according to the dependency order of bundles
        // create an AssetBundle object for each target
        foreach ($targets as $name => $target) {
            if (!isset($target['basePath'])) {
                throw new Exception("Please specify 'basePath' for the '$name' target.");
            }
            if (!isset($target['baseUrl'])) {
                throw new Exception("Please specify 'baseUrl' for the '$name' target.");
            }
            usort($target['depends'], function ($a, $b) use ($bundleOrders) {
                if ($bundleOrders[$a] == $bundleOrders[$b]) {
                    return 0;
                } else {
                    return $bundleOrders[$a] > $bundleOrders[$b] ? 1 : -1;
                }
            });
            $target['class'] = $name;
            $targets[$name] = Yii::createObject($target);
        }

        return $targets;
    }

    /**
     * Builds output asset bundle.
     * @param \yii\web\AssetBundle $target output asset bundle
     * @param string $type either 'js' or 'css'.
     * @param \yii\web\AssetBundle[] $bundles source asset bundles.
     * @throws Exception on failure.
     */
    protected function buildTarget($target, $type, $bundles)
    {
        $tempFile = $target->basePath . '/' . strtr($target->$type, ['{hash}' => 'temp']);
        $inputFiles = [];

        foreach ($target->depends as $name) {
            if (isset($bundles[$name])) {
                foreach ($bundles[$name]->$type as $file) {
                    $inputFiles[] = $bundles[$name]->basePath . '/' . $file;
                }
            } else {
                throw new Exception("Unknown bundle: '{$name}'");
            }
        }
        if ($type === 'js') {
            $this->compressJsFiles($inputFiles, $tempFile);
        } else {
            $this->compressCssFiles($inputFiles, $tempFile);
        }

        $outputFile = $target->basePath . '/' . strtr($target->$type, ['{hash}' => md5_file($tempFile)]);
        rename($tempFile, $outputFile);
        $target->$type = [$outputFile];
    }

    /**
     * Adjust dependencies between asset bundles in the way source bundles begin to depend on output ones.
     * @param \yii\web\AssetBundle[] $targets output asset bundles.
     * @param \yii\web\AssetBundle[] $bundles source asset bundles.
     * @return \yii\web\AssetBundle[] output asset bundles.
     */
    protected function adjustDependency($targets, $bundles)
    {
        echo "Creating new bundle configuration...\n";

        $map = [];
        foreach ($targets as $name => $target) {
            foreach ($target->depends as $bundle) {
                $map[$bundle] = $name;
            }
        }

        foreach ($targets as $name => $target) {
            $depends = [];
            foreach ($target->depends as $bn) {
                foreach ($bundles[$bn]->depends as $bundle) {
                    $depends[$map[$bundle]] = true;
                }
            }
            unset($depends[$name]);
            $target->depends = array_keys($depends);
        }

        // detect possible circular dependencies
        foreach ($targets as $name => $target) {
            $registered = [];
            $this->registerBundle($targets, $name, $registered);
        }

        foreach ($map as $bundle => $target) {
            $targets[$bundle] = Yii::createObject([
                'class' => 'yii\\web\\AssetBundle',
                'depends' => [$target],
            ]);
        }

        return $targets;
    }

    /**
     * Registers asset bundles including their dependencies.
     * @param \yii\web\AssetBundle[] $bundles asset bundles list.
     * @param string $name bundle name.
     * @param array $registered stores already registered names.
     * @throws Exception if circular dependency is detected.
     */
    protected function registerBundle($bundles, $name, &$registered)
    {
        if (!isset($registered[$name])) {
            $registered[$name] = false;
            $bundle = $bundles[$name];
            foreach ($bundle->depends as $depend) {
                $this->registerBundle($bundles, $depend, $registered);
            }
            unset($registered[$name]);
            $registered[$name] = true;
        } elseif ($registered[$name] === false) {
            throw new Exception("A circular dependency is detected for target '$name'.");
        }
    }

    /**
     * Saves new asset bundles configuration.
     * @param \yii\web\AssetBundle[] $targets list of asset bundles to be saved.
     * @param string $bundleFile output file name.
     * @throws \yii\console\Exception on failure.
     */
    protected function saveTargets($targets, $bundleFile)
    {
        $array = [];
        foreach ($targets as $name => $target) {
            foreach (['basePath', 'baseUrl', 'js', 'css', 'depends'] as $prop) {
                if (!empty($target->$prop)) {
                    $array[$name][$prop] = $target->$prop;
                } elseif (in_array($prop, ['js', 'css'])) {
                    $array[$name][$prop] = [];
                }
            }
        }
        $array = VarDumper::export($array);
        $version = date('Y-m-d H:i:s', time());
        $bundleFileContent = <<<EOD
<?php
/**
 * This file is generated by the "yii {$this->id}" command.
 * DO NOT MODIFY THIS FILE DIRECTLY.
 * @version {$version}
 */
return {$array};
EOD;
        if (!file_put_contents($bundleFile, $bundleFileContent)) {
            throw new Exception("Unable to write output bundle configuration at '{$bundleFile}'.");
        }
        echo "Output bundle configuration created at '{$bundleFile}'.\n";
    }

    /**
     * Compresses given JavaScript files and combines them into the single one.
     * @param array $inputFiles list of source file names.
     * @param string $outputFile output file name.
     * @throws \yii\console\Exception on failure
     */
    protected function compressJsFiles($inputFiles, $outputFile)
    {
        if (empty($inputFiles)) {
            return;
        }
        echo "  Compressing JavaScript files...\n";
        if (is_string($this->jsCompressor)) {
            $tmpFile = $outputFile . '.tmp';
            $this->combineJsFiles($inputFiles, $tmpFile);
            echo shell_exec(strtr($this->jsCompressor, [
                '{from}' => escapeshellarg($tmpFile),
                '{to}' => escapeshellarg($outputFile),
            ]));
            @unlink($tmpFile);
        } else {
            call_user_func($this->jsCompressor, $this, $inputFiles, $outputFile);
        }
        if (!file_exists($outputFile)) {
            throw new Exception("Unable to compress JavaScript files into '{$outputFile}'.");
        }
        echo "  JavaScript files compressed into '{$outputFile}'.\n";
    }

    /**
     * Compresses given CSS files and combines them into the single one.
     * @param array $inputFiles list of source file names.
     * @param string $outputFile output file name.
     * @throws \yii\console\Exception on failure
     */
    protected function compressCssFiles($inputFiles, $outputFile)
    {
        if (empty($inputFiles)) {
            return;
        }
        echo "  Compressing CSS files...\n";
        if (is_string($this->cssCompressor)) {
            $tmpFile = $outputFile . '.tmp';
            $this->combineCssFiles($inputFiles, $tmpFile);
            echo shell_exec(strtr($this->cssCompressor, [
                '{from}' => escapeshellarg($tmpFile),
                '{to}' => escapeshellarg($outputFile),
            ]));
            @unlink($tmpFile);
        } else {
            call_user_func($this->cssCompressor, $this, $inputFiles, $outputFile);
        }
        if (!file_exists($outputFile)) {
            throw new Exception("Unable to compress CSS files into '{$outputFile}'.");
        }
        echo "  CSS files compressed into '{$outputFile}'.\n";
    }

    /**
     * Combines JavaScript files into a single one.
     * @param array $inputFiles source file names.
     * @param string $outputFile output file name.
     * @throws \yii\console\Exception on failure.
     */
    public function combineJsFiles($inputFiles, $outputFile)
    {
        $content = '';
        foreach ($inputFiles as $file) {
            $content .= "/*** BEGIN FILE: $file ***/\n"
                . file_get_contents($file)
                . "/*** END FILE: $file ***/\n";
        }
        if (!file_put_contents($outputFile, $content)) {
            throw new Exception("Unable to write output JavaScript file '{$outputFile}'.");
        }
    }

    /**
     * Combines CSS files into a single one.
     * @param array $inputFiles source file names.
     * @param string $outputFile output file name.
     * @throws \yii\console\Exception on failure.
     */
    public function combineCssFiles($inputFiles, $outputFile)
    {
        $content = '';
        foreach ($inputFiles as $file) {
            $content .= "/*** BEGIN FILE: $file ***/\n"
                . $this->adjustCssUrl(file_get_contents($file), dirname($file), dirname($outputFile))
                . "/*** END FILE: $file ***/\n";
        }
        if (!file_put_contents($outputFile, $content)) {
            throw new Exception("Unable to write output CSS file '{$outputFile}'.");
        }
    }

    /**
     * Adjusts CSS content allowing URL references pointing to the original resources.
     * @param string $cssContent source CSS content.
     * @param string $inputFilePath input CSS file name.
     * @param string $outputFilePath output CSS file name.
     * @return string adjusted CSS content.
     */
    protected function adjustCssUrl($cssContent, $inputFilePath, $outputFilePath)
    {
        $sharedPathParts = [];
        $inputFilePathParts = explode('/', $inputFilePath);
        $inputFilePathPartsCount = count($inputFilePathParts);
        $outputFilePathParts = explode('/', $outputFilePath);
        $outputFilePathPartsCount = count($outputFilePathParts);
        for ($i =0; $i < $inputFilePathPartsCount && $i < $outputFilePathPartsCount; $i++) {
            if ($inputFilePathParts[$i] == $outputFilePathParts[$i]) {
                $sharedPathParts[] = $inputFilePathParts[$i];
            } else {
                break;
            }
        }
        $sharedPath = implode('/', $sharedPathParts);

        $inputFileRelativePath = trim(str_replace($sharedPath, '', $inputFilePath), '/');
        $outputFileRelativePath = trim(str_replace($sharedPath, '', $outputFilePath), '/');
        $inputFileRelativePathParts = explode('/', $inputFileRelativePath);
        $outputFileRelativePathParts = explode('/', $outputFileRelativePath);

        $callback = function ($matches) use ($inputFileRelativePathParts, $outputFileRelativePathParts) {
            $fullMatch = $matches[0];
            $inputUrl = $matches[1];

            if (preg_match('/^https?:\/\//is', $inputUrl) || preg_match('/^data:/is', $inputUrl)) {
                return $fullMatch;
            }

            $outputUrlParts = array_fill(0, count($outputFileRelativePathParts), '..');
            $outputUrlParts = array_merge($outputUrlParts, $inputFileRelativePathParts);

            if (strpos($inputUrl, '/') !== false) {
                $inputUrlParts = explode('/', $inputUrl);
                foreach ($inputUrlParts as $key => $inputUrlPart) {
                    if ($inputUrlPart == '..') {
                        array_pop($outputUrlParts);
                        unset($inputUrlParts[$key]);
                    }
                }
                $outputUrlParts[] = implode('/', $inputUrlParts);
            } else {
                $outputUrlParts[] = $inputUrl;
            }
            $outputUrl = implode('/', $outputUrlParts);

            return str_replace($inputUrl, $outputUrl, $fullMatch);
        };

        $cssContent = preg_replace_callback('/url\(["\']?([^)^"^\']*)["\']?\)/is', $callback, $cssContent);

        return $cssContent;
    }

    /**
     * Creates template of configuration file for [[actionCompress]].
     * @param string $configFile output file name.
     * @throws \yii\console\Exception on failure.
     */
    public function actionTemplate($configFile)
    {
        $jsCompressor = VarDumper::export($this->jsCompressor);
        $cssCompressor = VarDumper::export($this->cssCompressor);

        $template = <<<EOD
<?php
/**
 * Configuration file for the "yii asset" console command.
 * Note that in the console environment, some path aliases like '@webroot' and '@web' may not exist.
 * Please define these missing path aliases.
 */
return [
    // Adjust command/callback for JavaScript files compressing:
    'jsCompressor' => {$jsCompressor},
    // Adjust command/callback for CSS files compressing:
    'cssCompressor' => {$cssCompressor},
    // The list of asset bundles to compress:
    'bundles' => [
        // 'yii\web\YiiAsset',
        // 'yii\web\JqueryAsset',
    ],
    // Asset bundle for compression output:
    'targets' => [
        'app\assets\AllAsset' => [
            'basePath' => 'path/to/web',
            'baseUrl' => '',
            'js' => 'js/all-{hash}.js',
            'css' => 'css/all-{hash}.css',
        ],
    ],
    // Asset manager configuration:
    'assetManager' => [
        'basePath' => __DIR__,
        'baseUrl' => '',
    ],
];
EOD;
        if (file_exists($configFile)) {
            if (!$this->confirm("File '{$configFile}' already exists. Do you wish to overwrite it?")) {
                return self::EXIT_CODE_NORMAL;
            }
        }
        if (!file_put_contents($configFile, $template)) {
            throw new Exception("Unable to write template file '{$configFile}'.");
        } else {
            echo "Configuration file template created at '{$configFile}'.\n\n";
        }
    }
}
