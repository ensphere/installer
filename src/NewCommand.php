<?php

namespace Ensphere\Installer;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use STDclass;

class NewCommand extends Command
{

    protected $dbdetails;

    protected function configure()
    {
        $this
            ->setName( 'new' )
            ->setDescription( 'Create a new Ensphere application.' )
            ->addArgument( 'name', InputArgument::OPTIONAL );
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
            $question = new Question( 'What is your database name: ', false );
            if( ! $databaseName = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database name to complete the installation process.' );
            }
            $question = new Question( 'What is your database user: ', false );
            if( ! $databaseUser = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database username to complete the installation process.' );
            }
            $question = new Question( 'What is your database password: ', false );
            if( ! $databasePassword = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database password to complete the installation process.' );
            }
            $this->dbdetails->name = $databaseName;
            $this->dbdetails->user = $databaseUser;
            $this->dbdetails->pass = $databasePassword;
        }
        return $this->dbdetails;
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
        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new RuntimeException( 'The Zip PHP extension is not installed. Please install it and try again.' );
        }
        $name = strtolower( preg_replace( "/[^\w\d]+/i", '-', $input->getArgument( 'name' ) ) );
        $this->verifyApplicationDoesntExist(
            $directory = ( $name ) ? getcwd() . '/' . $name : getcwd()
        );

        $this->installAndSetupBackendApplication( $name, $directory, $input, $output );
        $this->installAndSetupFrontendApplication( $name, $directory, $input, $output );

        $output->writeln( '<comment>Application ready! Build something amazing.</comment>' );

    }

    protected function verifyApplicationDoesntExist( $directory )
    {
        if ( (is_dir( $directory) || is_file( $directory ) ) && $directory != getcwd() ) {
            throw new RuntimeException( 'Application already exists! Please delete it or rename it first.' );
        }
    }

    /**
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd() . '/ensphere_' . md5( time() . uniqid() ) . '.zip';
    }

    /**
     * @param $zipFile
     * @return $this
     */
    protected function download( $zipFile )
    {
        $response = ( new Client )->get( 'https://github.com/ensphere/ensphere/archive/master.zip' );
        file_put_contents( $zipFile, $response->getBody() );
        return $this;
    }

    /**
     * @param $zipFile
     * @param $directory
     * @return $this
     */
    protected function extract( $zipFile, $directory )
    {
        $archive = new ZipArchive;
        $archive->open( $zipFile );
        $archive->extractTo( $directory );
        $archive->close();
        return $this;
    }

    /**
     * @param $zipFile
     * @return $this
     */
    protected function cleanUp( $zipFile )
    {
        @chmod( $zipFile, 0777 );
        @unlink( $zipFile );
        return $this;
    }

    /**
     * @return string
     */
    protected function findComposer()
    {
        if( file_exists( getcwd() . '/composer.phar' ) ) {
            return '"' . PHP_BINARY . '" composer.phar';
        }
        return 'composer';
    }

    /**
     * @param $directory
     * @param $name
     * @param $position
     */
    protected function setupEnvExampleFile( $directory, $name, $position, $input, $output )
    {
        $env = file_get_contents( __DIR__ . '/.env.example' );
        $db = $this->getDatabaseDetails( $input, $output );
        $env = str_replace( [
            '[APP_URL]',
            '[DB_DATABASE]',
            '[DB_USERNAME]',
            '[DB_PASSWORD]'
        ], [
            "http://{$position}.{$name}.app",
            $db->name,
            $db->user,
            $db->pass,
        ], $env );
        file_put_contents( "{$directory}.env.example", $env );
    }

    /**
     * @param $directory
     * @param $data
     */
    protected function addModulesJsonFile( $directory, $data )
    {
        file_put_contents( "{$directory}modules.json", $data );
    }

    /**
     * @param $name
     * @param $directory
     * @param $input
     * @param $output
     */
    private function installAndSetupBackendApplication( $name, $directory, $input, $output )
    {
        $output->writeln( '<info>Creating Backend application...</info>' );
        $this->download( $zipFile = $this->makeFilename() )->extract( $zipFile, $directory )->cleanUp( $zipFile );
        rename( "{$directory}/ensphere-master", "{$directory}/{$name}-back" );
        $this->setupEnvExampleFile( "{$directory}/{$name}-back/", $name, 'back', $input, $output  );
        $this->addModulesJsonFile( "{$directory}/{$name}-back/", json_encode( [
            'purposemedia/authentication' => '^2.0',
            'purposemedia/module-manager' => '^2.0',
            'purposemedia/sites' => '^2.0',
            'purposemedia/users' => '^2.0'
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES ) );

        $this->installProject( "{$directory}/{$name}-back/", $output );
        $this->copyEnv( "{$directory}/{$name}-back/", $output );
        $this->generateNewKey( "{$directory}/{$name}-back/", $output );
        $this->composerUpdate( "{$directory}/{$name}-back/", $output );
        $this->composerUpdate( "{$directory}/{$name}-back/", $output );

    }

    /**
     * @param $name
     * @param $directory
     * @param $input
     * @param $output
     */
    private function installAndSetupFrontendApplication( $name, $directory, $input, $output )
    {
        $output->writeln( '<info>Creating Frontend application...</info>' );
        $this->download( $zipFile = $this->makeFilename() )->extract( $zipFile, $directory )->cleanUp( $zipFile );
        rename( "{$directory}/ensphere-master", "{$directory}/{$name}-front" );
        $this->setupEnvExampleFile( "{$directory}/{$name}-front/", $name, 'front', $input, $output );
        $this->addModulesJsonFile( "{$directory}/{$name}-front/", json_encode( [
            'purposemedia/front-container' => '^2.0',
            'purposemedia/front-sites' => '^2.0',
            'purposemedia/front-pages' => '^2.0'
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES ) );

        $this->installProject( "{$directory}/{$name}-front/", $output );
        $this->copyEnv( "{$directory}/{$name}-front/", $output );
        $this->generateNewKey( "{$directory}/{$name}-front/", $output );
        $this->composerUpdate( "{$directory}/{$name}-front/", $output );
        $this->composerUpdate( "{$directory}/{$name}-front/", $output );
    }

    /**
     * @param $path
     * @param $output
     */
    protected function installProject( $path, $output )
    {
        $this->runCommand( $output, $path, 'php composer.phar self-update; php composer.phar install --no-scripts' );
    }

    /**
     * @param $path
     * @param $output
     */
    protected function copyEnv( $path, $output )
    {
        $this->runCommand( $output, $path, "php -r \"copy('.env.example', '.env');\"" );
    }

    /**
     * @param $path
     * @param $output
     */
    protected function generateNewKey( $path, $output )
    {
        $this->runCommand( $output, $path, "php artisan key:generate" );
    }

    /**
     * @param $path
     * @param $output
     */
    protected function composerUpdate( $path, $output )
    {
        $this->runCommand( $output, $path, "php composer.phar update" );
    }

    /**
     * @param $output
     * @param $path
     * @param $command
     */
    protected function runCommand( $output, $path, $command )
    {
        $process = new Process( $command, $path, null, null, null );
        if ( '\\' !== DIRECTORY_SEPARATOR && file_exists( '/dev/tty' ) && is_readable( '/dev/tty' ) ) {
            $process->setTty( true );
        }
        $process->run( function ( $type, $line ) use ( $output ) {
            $output->write( $line );
        });
    }

}
