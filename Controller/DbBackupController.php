<?php

namespace TheliaDatabaseBackup\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Translation\Translator;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Response as TheliaResponse;
use Thelia\Model\ConfigQuery;
use Thelia\Log\Tlog;
use Propel\Runtime\Propel;
// Yaml n'est plus utilisé directement ici pour la config DB
// use Symfony\Component\Yaml\Yaml;

class DbBackupController extends BaseAdminController
{
    /**
     * @return mixed|Response
     */
    #[Route('/admin/module/TheliaDatabaseBackup', name: 'theliadatabasebackup.configuration')]
    public function viewAction(Request $request)
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], ['TheliaDatabaseBackup'], AccessManager::VIEW)) {
            return $response;
        }
        return $this->render('theliadatabasebackup-configuration');
    }


/**
 * @return Response|StreamedResponse
 */
#[Route('/admin/module/TheliaDatabaseBackup/download', name: 'theliadatabasebackup.download', methods: ['GET', 'POST'])]
public function download(Request $request)
{
    try {
        // Vérification des permissions
        $this->checkAuth([AdminResources::MODULE], ['TheliaDatabaseBackup'], AccessManager::VIEW);
        
        // Initialisation des variables de configuration DB
        $host = 'localhost';
        $port = 3306;
        $user = 'root';
        $pass = '';
        $dbname = '';

        // Récupération de la configuration de la base de données via le fichier .env
        try {
            $envPath = '';
            if (defined('THELIA_ROOT')) {
                $envPath = THELIA_ROOT . '.env.local';
            } else {
                // Chemin relatif depuis le répertoire du module Controller
                // Controller -> TheliaDatabaseBackup -> modules -> local -> THELIA_ROOT
                $envPath = __DIR__ . '/../../../../../.env.local';
            }

            if (!file_exists($envPath) || !is_readable($envPath)) {
                throw new \Exception("Configuration file .env not found or not readable at path: " . realpath($envPath ?: '.'));
            }

            $envConfig = $this->parseEnvFile($envPath);

            $host = $envConfig['DB_HOST'] ?? $host;
            $port = (int)($envConfig['DB_PORT'] ?? $port);
            $user = $envConfig['DB_USER'] ?? $user;
            $pass = $envConfig['DB_PASSWORD'] ?? $pass;
            $dbname = $envConfig['DB_NAME'] ?? $dbname;

        } catch (\Exception $e) {
            throw new \Exception(sprintf("Failed to load database configuration from .env file: %s", $e->getMessage()));
        }
        
        // Validation des paramètres de connexion
        if (empty($host) || empty($dbname)) {
            throw new \Exception("Database configuration is incomplete (host: $host, dbname: $dbname)");
        }
        
        // Génération du nom de fichier avec horodatage
        $filename = sprintf("%s_backup.sql", date("Y-m-d_H-i-s"));
        
        // Vérification que mysqldump est disponible
        $mysqldumpPath = $this->findMysqldumpPath();
        if (!$mysqldumpPath) {
            throw new \Exception("mysqldump command not found on system");
        }
        
        // Création de la réponse streamée
        $response = new StreamedResponse(function () use ($mysqldumpPath, $host, $port, $user, $pass, $dbname) {
            // Construction de la commande mysqldump
            $cmd = sprintf(
                '%s --host=%s --port=%d --user=%s --single-transaction --routines --triggers --lock-tables=false %s --all-databases 2>&1',
                escapeshellarg($mysqldumpPath),
                escapeshellarg($host),
                (int)$port,
                escapeshellarg($user),
                !empty($pass) ? '--password=' . escapeshellarg($pass) : ''
                // $dbname argument removed as --all-databases is used
            );
            
            // Exécution de la commande
            $process = popen($cmd, 'r');
            
            if (!$process) {
                throw new \Exception("Failed to execute mysqldump command");
            }
            
            // Stream du contenu
            while (!feof($process)) {
                $buffer = fread($process, 8192);
                if ($buffer !== false) {
                    echo $buffer;
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
            }
            
            $exitCode = pclose($process);
            
            if ($exitCode !== 0) {
                throw new \Exception("mysqldump failed with exit code: " . $exitCode);
            }
        });
        
        // Configuration des headers HTTP
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        
        // Log de l'action
        Tlog::getInstance()->info(sprintf("Database backup downloaded: %s", $filename));
        
        return $response;
        
    } catch (\Exception $e) {
        // Log de l'erreur
        Tlog::getInstance()->error(sprintf("Database backup download failed: %s", $e->getMessage()));
        
        // Retour d'une réponse d'erreur
        return new Response(
            sprintf("Error: %s", $e->getMessage()),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            ['Content-Type' => 'text/plain']
        );
    }
}

/**
 * Trouve le chemin vers mysqldump
 * 
 * @return string|false
 */
private function findMysqldumpPath()
{
    // Chemins possibles pour mysqldump
    $possiblePaths = [
        'mysqldump', // Dans le PATH
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        '/opt/homebrew/bin/mysqldump', // macOS avec Homebrew
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe', // Windows
        'C:\\xampp\\mysql\\bin\\mysqldump.exe', // XAMPP Windows
    ];
    
    foreach ($possiblePaths as $path) {
        if ($this->commandExists($path)) {
            return $path;
        }
    }
    
    return false;
}

/**
 * Vérifie si une commande existe
 * 
 * @param string $command
 * @return bool
 */
private function commandExists($command)
{
    $test = shell_exec(sprintf("which %s 2>/dev/null || where %s 2>nul", 
        escapeshellarg($command), 
        escapeshellarg($command)
    ));
    
    return !empty($test);
}

    /**
     * Parse a .env file and return an array of key-value pairs.
     *
     * @param string $filePath
     * @return array
     * @throws \Exception
     */
    private function parseEnvFile(string $filePath): array
    {
        $config = [];
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \Exception("Unable to read the .env file at {$filePath}");
        }

        foreach ($lines as $line) {
            // Ignore comments and section headers
            if (strpos(trim($line), '#') === 0 || strpos(trim($line), '###') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if any
                if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match("/^'(.*)'$/", $value, $matches)) {
                    $value = $matches[1];
                }
                $config[$key] = $value;
            }
        }
        return $config;
    }
}
