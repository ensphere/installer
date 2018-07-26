<?php

namespace Ensphere\Installer;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use RuntimeException;
use STDclass;

class NewFrontModuleCommand extends BaseCommand
{

    /**
     * @var array
     */
    protected $frontRequired = [
        'purposemedia/front-container',
        'purposemedia/front-sites',
    ];

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName( 'new:front-module' )
            ->setDescription( 'Create a new Front Ensphere module.' )
            ->addArgument( 'name', InputArgument::REQUIRED, 'Need a name' )
            ->addArgument( 'tld', InputArgument::OPTIONAL )
            ->setHelp( 'ensphere new:front-module "contact form"' );
    }

    /**
     * @return STDclass
     */
    protected function getDatabaseDetails( $input, $output )
    {
        if( is_null( $this->dbdetails ) ) {
            $output->writeln( '<error>SETUP YOUR DATABASE BEFORE ENTERING DETAILS!</error>' );
            $this->dbdetails = new STDclass;
            $helper = $this->getHelper( 'question' );

            $question = new ConfirmationQuestion( 'Are you using MAMP [y/n]', false );
            $usingMAMP = $helper->ask( $input, $output, $question );

            $question = new Question( 'What is your database host (127.0.0.1): ', '127.0.0.1' );
            if( ! $databaseHost = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database host to complete the installation process.' );
            }
            $question = new Question( 'What is your database port (3306): ', 3306 );
            if( ! $databasePort = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database port to complete the installation process.' );
            }
            $question = new Question( 'What is your database name: ', false );
            if( ! $databaseName = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database name to complete the installation process.' );
            }
            $question = new Question( 'What is your database user (homestead): ', 'homestead' );
            if( ! $databaseUser = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database username to complete the installation process.' );
            }
            $question = new Question( 'What is your database password (secret): ', 'secret' );
            if( ! $databasePassword = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database password to complete the installation process.' );
            }

            $this->dbdetails->name = $databaseName;
            $this->dbdetails->user = $databaseUser;
            $this->dbdetails->pass = $databasePassword;
            $this->dbdetails->host = $databaseHost;
            $this->dbdetails->mamp = $usingMAMP;
            $this->dbdetails->port = $databasePort;
            $this->connect();
        }
        return $this->dbdetails;
    }

    /**
     * @param $directory
     * @param string $position
     * @return void
     */
    protected function addModulesToComposerFile( $directory, $position = 'front' )
    {
        $composer = json_decode( file_get_contents( "{$directory}composer.json" ) );
        $required = $position === 'front' ? $this->frontRequired : $this->backRequired;
        foreach( $required as $name ) {
            $composer->require->{$name} = "*";
        }

        if( $position === 'front' ) {
            $composer->extra = new STDclass;
            $composer->extra->bower = new STDclass;
            $composer->extra->bower->require = new STDclass;
            $composer->extra->bower->require->jquery = '^1.8.0';
        }

        file_put_contents( "{$directory}composer.json", json_encode( $composer, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * @param $directory
     * @param $name
     * @param $position
     */
    protected function setupEnvExampleFile( $directory, $name, $position, $input, $output )
    {
        $tld = ( $customTld = $input->getArgument( 'tld' ) ) ? $customTld : '.localhost';
        $env = file_get_contents( __DIR__ . '/.env.example' );

        $coreArray = $position === 'front' ? $this->frontEndCoreEnvSettings() : $this->backEndCoreEnvSettings();
        $coreArray['APP_URL'] = "http://localhost:8000";

        if( $position === 'front' ) {
            $coreArray['FILESYSTEM_ROOT'] = "storage/app";
        } else {
            $coreArray['FRONT_END_URL'] = "http://localhost:8000";
            $coreArray['FRONT_END_FOLDER'] = "{$name}";
        }

        $db = $this->getDatabaseDetails( $input, $output );

        $env = str_replace( [
            '[ENSPHERE_CORE_SETTINGS]',
            '[APP_URL]',
            '[MAMP_SOCKET]',
            '[DB_HOST]',
            '[DB_DATABASE]',
            '[DB_USERNAME]',
            '[DB_PASSWORD]',
            '[DB_PORT]'
        ], [
            str_replace( [ '%2F', '%3A' ], [ '/', ':' ], http_build_query( $coreArray, '', "\n" ) ),
            "http://localhost:8000",
            $db->mamp ? 'DB_SOCKET=/Applications/MAMP/tmp/mysql/mysql.sock' : '',
            $db->host,
            $db->name,
            $db->user,
            $db->pass,
            $db->port
        ], $env );
        file_put_contents( "{$directory}.env", $env );
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
        $name = '_front-' . $this->getAppName( $input );
        $this->verifyApplicationDoesntExist(
            $directory = ( $name ) ? getcwd() . '/' . $name : getcwd(),
            'This module already exists.'
        );
        $moduleDirectory = "{$directory}/";
        $this->runCommand( $output, $directory, 'composer create-project ensphere/ensphere:dev-master ' . $name );
        $this->addModulesToComposerFile( $moduleDirectory, 'front' );
        $this->setupEnvExampleFile( $moduleDirectory, $name, 'front', $input, $output );
        $this->runCommand( $output, $moduleDirectory, 'composer update' );
    }

}
