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
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Ensphere\Installer\Models\User;
use Ensphere\Installer\Models\RoleUser;
use Illuminate\Hashing\BcryptHasher;
use STDclass;

class NewCommand extends Command
{

    protected $dbdetails;

    protected $capsule;

    protected $emailAddress;

    protected $version = '1.0.6';

    protected $hasher;

    protected $user;

    protected function connect()
    {
        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => 'localhost',
            'database'  => $this->dbdetails->name,
            'username'  => $this->dbdetails->user,
            'password'  => $this->dbdetails->pass,
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
        ]);
        $this->capsule->setEventDispatcher( new Dispatcher( new Container ) );
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
    }

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
            $question = new Question( 'What is your email address (required for admin login): ', false );
            if( ! $this->emailAddress = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a valid email address to complete the installation process.' );
            }
            $this->dbdetails->name = $databaseName;
            $this->dbdetails->user = $databaseUser;
            $this->dbdetails->pass = $databasePassword;
            $this->connect();
        }
        return $this->dbdetails;
    }

    /**
     * @param $output
     */
    protected function checkUsingLatestVersion( $output )
    {
        $data = json_decode( file_get_contents( 'https://packagist.org/p/ensphere/installer.json' ) );
        $versions = [];
        $tmpVersions = $data->packages->{'ensphere/installer'};
        foreach( $tmpVersions as $version => $package ) {
            $versions[] = $version;
        }
        usort( $versions, 'version_compare' );
        $latest = array_reverse( $versions )[0];
        if( $latest !== $this->version ) {
            throw new RuntimeException( "This version of the installer is out of date, please run 'composer global update \"ensphere/installer\"'." );
        }
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
        $this->hasher = new BcryptHasher();
        $this->checkUsingLatestVersion( $output );
        $name = strtolower( preg_replace( "/[^\w\d]+/i", '-', $input->getArgument( 'name' ) ) );
        $this->verifyApplicationDoesntExist(
            $directory = ( $name ) ? getcwd() . '/' . $name : getcwd()
        );

        $this->installAndSetupBackendApplication( $name, $directory, $input, $output );
        $this->installAndSetupFrontendApplication( $name, $directory, $input, $output );

        $output->writeln( "
            <fg=green>Your Ensphere application is successfully installed!</>\n\n
            <fg=blue>Credentials:</>\n\n
            <fg=yellow>Front URL:</> <fg=green>http://front.{$name}.app</>\n
            <fg=yellow>Back URL:</> <fg=green>http://back.{$name}.app</>\n
            <fg=yellow>Email:</> <fg=green>{$this->user->email}</>\n
            <fg=yellow>Password:</> <fg=green>{$this->user->password_plain}</>
        " );

    }

    /**
     * @param $directory
     */
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
            '[DB_PASSWORD]',
            '[FILESYSTEM_ROOT]'
        ], [
            "http://{$position}.{$name}.app",
            $db->name,
            $db->user,
            $db->pass,
            ( $position === 'back' ? 'storage/app' : "../{$name}-back/storage/app" )
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
        $newPath = "{$directory}/{$name}-back/";

        rename( "{$directory}/ensphere-master/", $newPath );
        $this->setupEnvExampleFile( $newPath, $name, 'back', $input, $output  );
        $this->addModulesJsonFile( $newPath, json_encode( [
            'purposemedia/authentication'   => '^2.0',
            'purposemedia/module-manager'   => '^2.0',
            'purposemedia/sites'            => '^2.0',
            'purposemedia/users'            => '^2.0'
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES ) );

        $this->installProject( $newPath, $output );
        $this->copyEnv( $newPath, $output );
        $this->generateNewKey( $newPath, $output );
        $this->composerUpdate( $newPath, $output );
        $this->composerUpdate( $newPath, $output );
        $this->createUser( $name );
    }

    /**
     * @param $name
     */
    protected function createUser( $name )
    {
        $password = substr( sha1( microtime() ), 0, 8 );
        $user = User::create([
            'email'     => $this->emailAddress,
            'password'  => $this->hasher->make( $password ),
            'active'    => 1
        ]);
        RoleUser::create([
            'role_id' => 1,
            'user_id' => 1
        ]);

        $user->password_plain = $password;
        $this->user = $user;

        $message = "
        Your main account has been created for {$name}.\n\n
        Email: {$user->email}\n
        Password: {$password}\n\n
        You will be granted Keymaster permissions.";
        mail( $this->emailAddress, 'Ensphere Project Authentication', $message );
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
        $newPath = "{$directory}/{$name}-front/";

        rename( "{$directory}/ensphere-master/", $newPath );
        $this->setupEnvExampleFile( $newPath, $name, 'front', $input, $output );
        $this->addModulesJsonFile( $newPath, json_encode( [
            'purposemedia/front-container'  => '^2.0',
            'purposemedia/front-sites'      => '^2.0',
            'purposemedia/front-pages'      => '^2.0'
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES ) );

        $this->installProject( $newPath, $output );
        $this->copyEnv( $newPath, $output );
        $this->generateNewKey( $newPath, $output );
        $this->composerUpdate( $newPath, $output );
        $this->composerUpdate( $newPath, $output );
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
