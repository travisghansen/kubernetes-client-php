<?php

namespace KubernetesClient;

use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;
use Symfony\Component\Yaml\Yaml;

class Config
{
    /**
     * Provides a file name prefix to use for temporary files
     *
     * @var string
     */
    private static $temp_file_prefix = 'kubernetes-client-';

    /**
     * Keep track of temporary files created for cleanup
     *
     * @var array
     */
    private static $tempFiles = [];

    /**
     * Path to the operative config file
     *
     * @var string
     */
    private $path;

    /**
     * Server URI (ie: https://host)
     *
     * @var string
     */
    private $server;

    /**
     * Struct of cluster from context
     *
     * @var array
     */
    private $cluster;

    /**
     * Struct of user from context
     *
     * @var array
     */
    private $user;

    /**
     * Struct of context
     *
     * @var array
     */
    private $context;

    /**
     * Active in-use context
     *
     * @var string
     */
    private $activeContextName;

    /**
     * Path to client PEM certificate
     *
     * @var string
     */
    private $clientCertificatePath ;

    /**
     * Path to client PEM key
     *
     * @var string
     */
    private $clientKeyPath;

    /**
     * Path to cluster CA PEM
     *
     * @var string
     */
    private $certificateAuthorityPath;

    /**
     * Authorization token
     *
     * @var string
     */
    private $token;

    /**
     * timestamp of token expiration
     *
     * @var int
     */
    private $expiry;

    /**
     * If the user token is generated via an auth provider such as gcp or azure
     *
     * @var bool
     */
    private $isAuthProvider = false;

    /**
     * If the user auth data is generated via an exec provider
     *
     * @var bool
     */
    private $isExecProvider = false;

    /**
     * Data from parsed config file
     *
     * @var array
     */
    private $parsedConfigFile;


    /**
     * Create a temporary file to be used and destroyed at shutdown
     *
     * @param  $data
     * @return bool|string
     */
    private static function writeTempFile($data)
    {
        $file = tempnam(sys_get_temp_dir(), self::$temp_file_prefix);
        file_put_contents($file, $data);

        self::$tempFiles[] = $file;

        /*
        register_shutdown_function(function () use ($file) {
            if (file_exists($file)) {
                unlink($file);
            }
        });
        */

        return $file;
    }

    /**
     * Clean up temp files
     *
     * @param $path
     */
    private static function deleteTempFile($path)
    {
        if ((bool) $path && in_array($path, self::$tempFiles) && file_exists($path)) {
            unlink($path);
            self::$tempFiles = array_filter(
                self::$tempFiles, function ($e) use ($path) {
                    return ($e !== $path);
                }
            );
        }
    }

    /**
     * handle php shutdown
     */
    public static function shutdown()
    {
        foreach (self::$tempFiles as $tempFile) {
            self::deleteTempFile($tempFile);
        }
    }

    /**
     * Create a config from KUBECONFIG env variable if present or ~/.kube/config if found.
     * Otherwise try to create a config based off running inside a cluster if corresponding files found.
     *
     * @return Config
     * @throws \Error If no config can be found at at all the default paths.
     */
    public static function LoadFromDefault()
    {
        try {
            return self::BuildConfigFromFile();
        } catch (\Error $e) {
            return self::InClusterConfig();
        }
    }

    /**
     * Create a config based off running inside a cluster
     *
     * @return Config
     * @throws \Error
     */
    public static function InClusterConfig()
    {
        if (!file_exists('/var/run/secrets/kubernetes.io/serviceaccount/token')) {
            throw new \Error('Config based off running inside a cluster not available. Token not found.');
        }

        if (!file_exists('/var/run/secrets/kubernetes.io/serviceaccount/ca.crt')) {
            throw new \Error('Config based off running inside a cluster not available. CA not found.');
        }

        $config = new Config();
        $config->setToken(file_get_contents('/var/run/secrets/kubernetes.io/serviceaccount/token'));
        $config->setCertificateAuthorityPath('/var/run/secrets/kubernetes.io/serviceaccount/ca.crt');

        if (strlen(getenv('KUBERNETES_SERVICE_HOST')) > 0) {
            $server = 'https://' . getenv('KUBERNETES_SERVICE_HOST') . ':' . getenv('KUBERNETES_SERVICE_PORT');
        } else {
            $server = 'https://kubernetes.default.svc';
        }

        $config->setServer($server);

        return $config;
    }

    /**
     * Create a config from file will auto fallback to KUBECONFIG env variable or ~/.kube/config if no path supplied
     *
     * @param  null $path
     * @param  null $contextName
     * @return Config
     * @throws \Error
     */
    public static function BuildConfigFromFile($path = null, $contextName = null)
    {
        if (empty($path)) {
            $path = getenv('KUBECONFIG');
        }

        if (empty($path)) {
            $path = getenv('HOME').'/.kube/config';
        }

        if (!file_exists($path)) {
            throw new \Error('Config file does not exist: ' . $path);
        }


        if (function_exists('yaml_parse_file')) {
            $yaml = yaml_parse_file($path);
            if (false === $yaml) {
                throw new \Error('Unable to parse YAML.');
            }
        } else {
            try {
                $yaml = Yaml::parseFile($path);
            } catch (\Throwable $th) {
                throw new \Error('Unable to parse', 0, $th);
            }
        }

        if (empty($contextName)) {
            $contextName = $yaml['current-context'];
        }

        $config = new Config();
        $config->setPath($path);
        $config->setParsedConfigFile($yaml);
        $config->useContext($contextName);

        return $config;
    }

    /**
     * destruct
     */
    public function __destruct()
    {
        /**
         * @note these are only deleted if they were created as temp files
         */
        self::deleteTempFile($this->certificateAuthorityPath);
        self::deleteTempFile($this->clientCertificatePath);
        self::deleteTempFile($this->clientKeyPath);
    }

    /**
     * Switch contexts
     *
     * @param $contextName
     */
    public function useContext($contextName)
    {
        $this->resetAuthData();
        $this->setActiveContextName($contextName);
        $yaml = $this->getParsedConfigFile();
        $context = null;
        foreach ($yaml['contexts'] as $item) {
            if ($item['name'] == $contextName) {
                $context = $item['context'];
                break;
            }
        }

        $cluster = null;
        foreach ($yaml['clusters'] as $item) {
            if ($item['name'] == $context['cluster']) {
                $cluster = $item['cluster'];
                break;
            }
        }

        $user = null;
        foreach ($yaml['users'] as $item) {
            if ($item['name'] == $context['user']) {
                $user = $item['user'];
                break;
            }
        }

        $this->setContext($context);
        $this->setCluster($cluster);
        $this->setUser($user);
        $this->setServer($cluster['server']);

        if (!empty($cluster['certificate-authority-data'])) {
            $path = self::writeTempFile(base64_decode($cluster['certificate-authority-data'], true));
            $this->setCertificateAuthorityPath($path);
        }

        if (!empty($user['client-certificate-data'])) {
            $path = self::writeTempFile(base64_decode($user['client-certificate-data']));
            $this->setClientCertificatePath($path);
        }

        if (!empty($user['client-key-data'])) {
            $path = self::writeTempFile(base64_decode($user['client-key-data']));
            $this->setClientKeyPath($path);
        }

        if (!empty($user['token'])) {
            $this->setToken($user['token']);
        }

        // should never have both auth-provider and exec at the same time

        if (!empty($user['auth-provider'])) {
            $this->setIsAuthProvider(true);
        }

        if (!empty($user['exec'])) {
            $this->setIsExecProvider(true);
            // we pre-emptively invoke this in this case
            $this->getExecProviderAuth();
        }
    }

    /**
     * Reset relevant data when context switching
     */
    protected function resetAuthData()
    {
        $this->setCertificateAuthorityPath(null);
        $this->setClientCertificatePath(null);
        $this->setClientKeyPath(null);
        $this->setExpiry(null);
        $this->setToken(null);
        $this->setIsAuthProvider(false);
        $this->setIsExecProvider(false);
    }

    /**
     * Set path
     *
     * @param $path
     */
    public function setPath($path)
    {
        if (!empty($path)) {
            $path = realpath(($path));
        }

        $this->path = $path;
    }

    /**
     * Get path
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Set server
     *
     * @param $server
     */
    public function setServer($server)
    {
        $this->server = $server;
    }

    /**
     * Get server
     *
     * @return string
     */
    public function getServer()
    {
        return $this->server;
    }

    /**
     * Set user
     *
     * @param $user array
     */
    public function setUser($user)
    {
        $this->user = $user;
    }

    /**
     * Get user
     *
     * @return array
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set context
     *
     * @param $context array
     */
    public function setContext($context)
    {
        $this->context = $context;
    }

    /**
     * Set context
     *
     * @return array
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Set cluster
     *
     * @param $cluster array
     */
    public function setCluster($cluster)
    {
        $this->cluster = $cluster;
    }

    /**
     * Get cluster
     *
     * @return array
     */
    public function getCluster()
    {
        return $this->cluster;
    }

    /**
     * Set activeContextName
     *
     * @param $name string
     */
    protected function setActiveContextName($name)
    {
        $this->activeContextName = $name;
    }

    /**
     * Get activeContextName
     *
     * @return string
     */
    public function getActiveContextName()
    {
        return $this->activeContextName;
    }

    /**
     * Set token
     *
     * @param $token
     */
    public function setToken($token)
    {
        $this->token = $token;
    }

    /**
     * Get token
     *
     * @throws JSONPathException
     * @return string
     */
    public function getToken()
    {
        if ($this->getIsAuthProvider()) {
            // set token if expired
            if ($this->getExpiry() && time() >= $this->getExpiry()) {
                $this->getAuthProviderToken();
            }

            // set token if we do not have one yet
            if (empty($this->token)) {
                $this->getAuthProviderToken();
            }
        }

        // only do this if token is present to begin with
        if ($this->getIsExecProvider() && !empty($this->token)) {
            // set token if expired
            if ($this->getExpiry() && time() >= $this->getExpiry()) {
                $this->getExecProviderAuth();
            }
        }

        return $this->token;
    }

    /**
     * @link https://github.com/kubernetes-client/javascript/blob/master/src/cloud_auth.ts - Official JS Implementation
     *
     * Set the token and expiry when using an auth provider
     *
     * @throws JSONPathException
     * @throws Error
     */
    protected function getAuthProviderToken()
    {
        $user = $this->getUser();

        // gcp, azure, etc
        //$name = (new JSONPath($user))->find('$.auth-provider.name')->first();

        // build command
        $cmd_path = (new JSONPath($user))->find('$.auth-provider.config.cmd-path')->first();
        $cmd_args = (new JSONPath($user))->find('$.auth-provider.config.cmd-args')->first();

        if (!$cmd_path) {
            throw new \Error('error finding access token command. No command found.');
        }

        $command = $cmd_path;
        if ($cmd_args) {
            $command .= ' ' . $cmd_args;
        }

        // execute command and store output
        $output = [];
        $exit_code = null;
        exec($command, $output, $exit_code);
        $output = implode("\n", $output);

        if ($exit_code !== 0) {
            throw new \Error("error executing access token command \"{$command}\": {$output}");
        } else {
            $output = json_decode($output, true);
        }

        if (!is_array($output) || empty($output)) {
            throw new \Error("error retrieving token: auth provider failed to return valid data");
        }

        $expiry_key = (new JSONPath($user))->find('$.auth-provider.config.expiry-key')->first();
        $token_key = (new JSONPath($user))->find('$.auth-provider.config.token-key')->first();

        if ($expiry_key) {
            $expiry_key = '$' . trim($expiry_key, "{}");
            $expiry = (new JSONPath($output))->find($expiry_key)->first();
            if ($expiry) {
                // No expiry should be ok, thus never expiring token
                $this->setExpiry($expiry);
            }
        }

        if ($token_key) {
            $token_key = '$' . trim($token_key, "{}");
            $token = (new JSONPath($output))->find($token_key)->first();
            if (!$token) {
                throw new \Error(sprintf('error retrieving token: token not found. Searching for key: "%s"', $token_key));
            }
            $this->setToken($token);
        }
    }

    /**
     * @link https://kubernetes.io/docs/reference/access-authn-authz/authentication/#client-go-credential-plugins
     * @link https://banzaicloud.com/blog/kubeconfig-security/#exec-helper
     *
     * Set the auth data using the exec provider
     */
    protected function getExecProviderAuth()
    {
        $user = $this->getUser();
        $path = $this->getPath();

        $command = $user['exec']['command'];

        // relative commands should be executed relative to the directory holding the config file
        if (substr($command, 0, 1) == ".") {
            $dir = dirname($path);
            $command = $dir . substr($command, 1);
        }

        // add args
        if (!empty($user['exec']['args'])) {
            foreach ($user['exec']['args'] as $arg) {
                $command .= " " . $arg;
            }
        }

        // set env
        // beware this sets the env var for the whole process indefinitely
        if (!empty($user['exec']['env'])) {
            foreach ($user['exec']['env'] as $env) {
                putenv("{$env['name']}={$env['value']}");
            }
        }

        // execute command and store output
        $output = [];
        $exit_code = null;
        exec($command, $output, $exit_code);
        $output = implode("\n", $output);

        if ($exit_code !== 0) {
            throw new \Error("error executing access token command \"{$command}\": {$output}");
        } else {
            $output = json_decode($output, true);
        }

        if (!is_array($output)) {
            throw new \Error("error retrieving token: auth exec failed to return valid data");
        }

        if ($output["kind"] != "ExecCredential") {
            throw new \Error("error retrieving auth: auth exec failed to return valid data");
        }

        if ($output['apiVersion'] != 'client.authentication.k8s.io/v1beta1') {
            throw new \Error("error retrieving auth: auth exec unsupported apiVersion");
        }

        if (!empty($output['status']['clientCertificateData'])) {
            $path = self::writeTempFile($output['status']['clientCertificateData']);
            $this->setClientCertificatePath($path);
        }

        if (!empty($output['status']['clientKeyData'])) {
            $path = self::writeTempFile($output['status']['clientKeyData']);
            $this->setClientKeyPath($path);
        }

        if (!empty($output['status']['expirationTimestamp'])) {
            $this->setExpiry($output['status']['expirationTimestamp']);
        }

        if (!empty($output['status']['token'])) {
            $this->setToken($output['status']['token']);
        }
    }

    /**
     * Set expiry
     *
     * @param $expiry
     */
    public function setExpiry($expiry)
    {
        if (!empty($expiry) && !is_int($expiry)) {
            $expiry = strtotime($expiry);
        }
        $this->expiry = $expiry;
    }

    /**
     * Get expiry
     *
     * @return int
     */
    public function getExpiry()
    {
        return $this->expiry;
    }

    /**
     * Set client certificate path
     *
     * @param $path
     */
    public function setClientCertificatePath($path)
    {
        self::deleteTempFile($this->clientCertificatePath);
        $this->clientCertificatePath = $path;
    }

    /**
     * Get client certificate path
     *
     * @return string
     */
    public function getClientCertificatePath()
    {
        return $this->clientCertificatePath;
    }

    /**
     * Set client key path
     *
     * @param $path
     */
    public function setClientKeyPath($path)
    {
        self::deleteTempFile($this->clientKeyPath);
        $this->clientKeyPath = $path;
    }

    /**
     * Get client key path
     *
     * @return string
     */
    public function getClientKeyPath()
    {
        return $this->clientKeyPath;
    }

    /**
     * Set cluster CA path
     *
     * @param $path
     */
    public function setCertificateAuthorityPath($path)
    {
        self::deleteTempFile($this->certificateAuthorityPath);
        $this->certificateAuthorityPath = $path;
    }

    /**
     * Get cluster CA path
     *
     * @return string
     */
    public function getCertificateAuthorityPath()
    {
        return $this->certificateAuthorityPath;
    }

    /**
     * Set if user credentials use auth provider
     *
     * @param $v bool
     */
    public function setIsAuthProvider($v)
    {
        $this->isAuthProvider = $v;
    }

    /**
     * Get if user credentials use auth provider
     *
     * @return bool
     */
    public function getIsAuthProvider()
    {
        return $this->isAuthProvider;
    }

    /**
     * Set if user credentials use exec provider
     *
     * @param $v bool
     */
    public function setIsExecProvider($v)
    {
        $this->isExecProvider = $v;
    }

    /**
     * Get if user credentials use exec provider
     *
     * @return bool
     */
    public function getIsExecProvider()
    {
        return $this->isExecProvider;
    }

    /**
     * Set the data of the parsed config file
     *
     * @param $data array
     */
    public function setParsedConfigFile($data)
    {
        $this->parsedConfigFile = $data;
    }

    /**
     * Get the data of the parsed config file
     *
     * @return array
     */
    public function getParsedConfigFile()
    {
        return $this->parsedConfigFile;
    }
}

register_shutdown_function(array('KubernetesClient\Config', 'shutdown'));
