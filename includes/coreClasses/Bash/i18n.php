<?php

namespace Igestis\Bash;
/**
 * Description of IgestisScripts
 *
 * @author Gilles Hemmerlé <giloux@gmail.com>
 */

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class i18n extends Command {
    protected function configure()
    {
        $this
            ->setName('i18n:generate')
            ->setDescription('Generate the PO file to include the last found texts')
            ->addArgument(
                'moduleName',
                InputArgument::REQUIRED,
                'Module name of the module that you want regenerate locales for. (specify "core" for the igestis core)'
            )
        ;
        
        
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $moduleName = $input->getArgument('moduleName');
        
        $modulesList = \IgestisModulesList::getInstance();
        
        if($moduleName != "core" && (!$modulesList->moduleExists($moduleName) || !$modulesList->getFolder($moduleName))) {
            $output->writeln("<error>" . sprintf(\Igestis\I18n\Translate::_("The '%s' module doest not exist"), $moduleName) . "</error>");
            exit(1);
        }
        
        $output->writeln($moduleName);
        $output->writeln($modulesList->getFolder('core') . '/templates/');
        $tplDir = $modulesList->getFolder($moduleName) . '/templates/';
        
        $tmpDir = sys_get_temp_dir() . "/igestis/$moduleName-" . uniqid() . "/";
        exec('mkdir -p ' . escapeshellarg($tmpDir));
        
        $langDir = $modulesList->getFolder($moduleName) . '/lang/locale';
        if(!is_dir($langDir)) {
            exec('mkdir -p ' . escapeshellarg($langDir));
        }
        
        
        
        $igestis = \Application::getInstance();
        $twigEnv = $igestis->getTwigEnvironnement();
        $twigEnv->getLoader()->setPaths(array($tplDir, $modulesList->getFolder('core') . '/templates/'));
        
        // iterate over all your templates
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tplDir), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            // force compilation
            if (!is_file($file)) {
                continue;
            }
            
            $output->writeln("<info>" . sprintf(\Igestis\I18n\Translate::_("Compiling '%s' ..."), str_replace($tplDir, '', $file)) . "</info>");
            $twigEnv->loadTemplate(str_replace($tplDir, '', $file));
        }
        
        $potFileName = "messages-" . date("Y-m-m-h-i-s") . ".pot";
        exec("find " . escapeshellarg($modulesList->getFolder($moduleName)) . " -name \\*.php > " . $tmpDir . "phpfiles");
        exec("find $tmpDir -name \\*.php -exec sed -i 's/>dgettext/ gettext/g' {} \;");
        exec("xgettext --default-domain=messages -p " . $langDir . " -o" . $potFileName . " --from-code=UTF-8 -n --omit-header -L PHP -f " . $tmpDir . "phpfiles");
        exec("rm -rf '$tmpDir'");
        
        $output->writeln("<info>" . sprintf(\Igestis\I18n\Translate::_("Done. The new '%s' POT file has been created."), $langDir . "/" . $potFileName) . "</info>");
    }
}