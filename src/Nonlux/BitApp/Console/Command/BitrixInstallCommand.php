<?php


namespace Nonlux\BitApp\Console\Command;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Nonlux\BitApp\Bitrix\Main\Option;
use Nonlux\BitApp\Bitrix\NewCreateModuleStep;
use Nonlux\BitApp\Bitrix\Step\CreateAdminStep;
use Nonlux\BitApp\Bitrix\Step\CreateDBStep;
use Nonlux\BitApp\Bitrix\Step\CreateModulesStep;
use Nonlux\BitApp\Bitrix\Step\FinishStep;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class BitrixInstallCommand extends Command
{

    protected $projectPath;
    protected $config;

    public function __construct($projectPath, $config)
    {
        $this->projectPath = $projectPath;
        $this->config = $config;
        $this->config['password']= $this->config['user_password'];
        $this->config['bitrixRoot'] =$this->projectPath;
        $this->config['admin_password_confirm']=$this->config['admin_password'];
        parent::__construct();

    }

    protected function configure()
    {
        $this->setName("bitrix:install");
        $this->setDescription("install bitrix");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $DB, $DBType, $DBHost, $DBLogin, $DBPassword, $DBName, $DBDebug, $DBDebugToFile, $APPLICATION, $USER, $arWizardConfig, $MESS;
        $bitrixRoot = $this->projectPath;
        $output->writeln("Install bitrix... in  $bitrixRoot");
        $_SERVER["DOCUMENT_ROOT"] = $bitrixRoot;
        $_SERVER["REQUEST_URI"] = "/index.php";
        $_SERVER["QUERY_STRING"] = "";
        define("B_PROLOG_INCLUDED", true);
        ob_start();
        require_once("$bitrixRoot/bitrix/modules/main/install/wizard/wizard.php");
        ob_end_clean();
        $st=1;
        $output->writeln("Step $st. Create database:");
        ++$st;
        $wizard = new \CWizardBase("nonlux.createDb.wizard", null);

        $dbName = $this->config['database'];
        $output->writeln("database name: $dbName");
        $data = $this->getConfig(array(
            "agree_license",
            "user" ,
            "password",
            "database",
            "utf8",
            "dbType",
            "host",
            "create_user",
            "create_database",
            "root_user",
            "root_password",
            'file_access_perms',
            'folder_access_perms',
            'bitrixRoot'
        ));


        foreach ($data as $key => $value) {
            $wizard->SetVar($key, $value);
        }
        $step = new \CreateDBStep();
        $wizard->AddStep($step);
        $step->OnPostForm();
        $errors=$step->GetErrors();
        if (isset($errors[0])){
            $last_error=iconv('cp1251', 'utf-8', $errors[0][0]);
            throw new \Exception($last_error);
        }
        $output->writeln("Done");
        $output->writeln("Step $st. Generate config files:");
        ++$st;
        $settings=sprintf("<?php
return array (
        'className' => '\\Bitrix\\Main\\DB\\MysqliConnection',
        'host' => '%s',
        'database' => '%s',
        'login' => '%s',
        'password' => '%s',
        'options' => 2,
      );
", $data['host'],$data['database'],$data['user'],$data['password']);
        file_put_contents($bitrixRoot.'/bitrix/.db_settings.php', $settings);
        $settings="<?php return require(__DIR__.'/.settings_prod.php');";
        file_put_contents($bitrixRoot.'/bitrix/.settings.php', $settings);

        $settings="<?php return require(__DIR__.'/dbconn_prod.php');";
        file_put_contents($bitrixRoot.'/bitrix/.settings.php', $settings);

        $output->writeln("Done");

        require_once $bitrixRoot . '/bitrix/php_interface/dbconn.php';

        $output->writeln("Step $st. Install modules:");
        ++$st;

        $wizard = new \CWizardBase("nonlux.installModules.wizard", null);
        $data = array_merge(
            array(
                "nextStep" => "main",
                "nextStepStage" => "utf8",
            ),
            $this->getConfig(
                array(
                    'bitrixRoot',
                    "user",
                    "password",
                    "utf8",
                )
            )
        );
        $step = new CreateModulesStep();
        $wizard->AddStep($step);
        foreach ($data as $key => $value) {
            $wizard->SetVar($key, $value);
        }
        do {
            $output->writeln("Install " . $wizard->GetVar("nextStep") . " " . $wizard->GetVar("nextStepStage"));

            $step->OnPostForm();
            if ($wizard->GetVar("nextStep") === 'main' && $wizard->GetVar("nextStepStage") === 'files') {
                $HttpApplication = \Bitrix\Main\HttpApplication::getInstance();
                $HttpApplication->initializeBasicKernel();
                $HttpApplication->getCache()->clearCache(true);
                $GLOBALS['CACHE_MANAGER']->Clean('b_option');
                Option::clearOptions("main");
            }
        } while ($wizard->GetVar('nextStep') != '__finish');

        $output->writeln("Done");

        $USER = new \CUser;
        $policy = $USER->GetSecurityPolicy();
        $output->writeln("Step $st. Create admin:");
        ++$st;
        $data = $this->getConfig(array(
            'email',
            'login',
            'admin_password_confirm',
            'admin_password',
            'user_name',
            "utf8",
            'user_surname'
        ));

        foreach ($data as $key => $value) {
            $wizard->SetVar($key, $value);
        }
        $wizard = new \CWizardBase("nonlux.admin.wizard", null);
        $step = new \CreateAdminStep();
        $wizard->AddStep($step);
        $step->OnPostForm();

        $output->writeln("Done");
        $step = new \FinishStep();
        $step->ShowStep();

    }

    protected function  getConfig ($keys){
        $ret=array();
        foreach ($keys as $key){
            if (array_key_exists($key,$this->config)){
                $ret[$key]=$this->config[$key];
            }
            else {
                switch ($key){
                    default:
                        throw new \Exception("Key $key not found");
                }
            }
        }

        return $ret;
    }


}
