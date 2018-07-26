<?php

namespace Ensphere\Installer;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NewAdminModuleCommand extends NewFrontModuleCommand
{

    /**
     * @var array
     */
    protected $backRequired = [
        'purposemedia/sites',
        'purposemedia/users',
        'purposemedia/authentication',
    ];

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName( 'new:admin-module' )
            ->setDescription( 'Create a new Admin Ensphere module.' )
            ->addArgument( 'name', InputArgument::REQUIRED, 'Need a name' )
            ->addArgument( 'tld', InputArgument::OPTIONAL )
            ->setHelp( 'ensphere new:admin-module "contact form"' );
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function execute( InputInterface $input, OutputInterface $output )
    {
        $this->checkUsingLatestVersion( $output );
        $name = '_admin-' . $this->getAppName( $input );
        $this->verifyApplicationDoesntExist(
            $directory = ( $name ) ? getcwd() . '/' . $name : getcwd(),
            'This module already exists.'
        );
        $moduleDirectory = "{$directory}/";
        $this->runCommand( $output, $directory, 'composer create-project ensphere/ensphere:dev-master ' . $name );
        $this->addModulesToComposerFile( $moduleDirectory, 'back' );
        $this->setupEnvExampleFile( $moduleDirectory, $name, 'back', $input, $output );
        $this->runCommand( $output, $moduleDirectory, 'composer update' );
    }

}
