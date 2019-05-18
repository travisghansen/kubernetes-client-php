<?php

namespace KubernetesClient;

class Config
{
    /**
     * Provides a file name prefix to use for temporary files
     *
     * @var string
     */
    private static $temp_file_prefix = 'kubernetes-client-';

    /**
     * Server URI (ie: https://host)
     *
     * @var string
     */
    private $server;

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
     * Create a temporary file to be used and destroyed at shutdown
     *
     * @param $data
     * @return bool|string
     */
    private static function writeTempFile($data)
    {
        $file = tempnam(sys_get_temp_dir(), self::$temp_file_prefix);
        file_put_contents($file, $data);

        register_shutdown_function(function () use ($file) {
            if (file_exists($file)) {
                unlink($file);
            }
        });

        return $file;
    }

    /**
     * Create a config based off running inside a cluster
     *
     * @return Config
     */
    public static function InClusterConfig()
    {
        $config = new Config();
        $config->setToken(file_get_contents('/var/run/secrets/kubernetes.io/serviceaccount/token'));
        $config->setCertificateAuthorityPath('/var/run/secrets/kubernetes.io/serviceaccount/ca.crt');
        $config->setServer('https://kubernetes.default.svc');

        return $config;
    }

    /**
     * Create a config from file will auto fallback to KUBECONFIG env variable or ~/.kube/config if no path supplied
     *
     * @param null $path
     * @return Config
     * @throws \Exception
     */
    public static function BuildConfigFromFile($path = null)
    {
        if (empty($path)) {
            $path = getenv('KUBECONFIG');
        }

        if (empty($path)) {
            $path = getenv('HOME').'/.kube/config';
        }

        if (!file_exists($path)) {
            throw new \Exception('Config file does not exist: ' . $path);
        }

        $yaml = yaml_parse_file($path);

        $currentContextName = $yaml['current-context'];
        $context = null;
        foreach ($yaml['contexts'] as $item) {
            if ($item['name'] == $currentContextName) {
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

        $config = new Config();
        $config->setServer($cluster['server']);

        if (!empty($cluster['certificate-authority-data'])) {
            $path = self::writeTempFile(base64_decode($cluster['certificate-authority-data'], true));
            $config->setCertificateAuthorityPath($path);
        }

        if (!empty($user['client-certificate-data'])) {
            $path = self::writeTempFile(base64_decode($user['client-certificate-data']));
            $config->setClientCertificatePath($path);
        }

        if (!empty($user['client-key-data'])) {
            $path = self::writeTempFile(base64_decode($user['client-key-data']));
            $config->setClientKeyPath($path);
        }

        // Handles the case where you have a kubeconfig for a service account
        if (!empty($user['token'])) {
            $path = self::writeTempFile(base64_decode($user['token']));
            $config->setToken($path);
        }

        return $config;
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
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Set client certificate path
     *
     * @param $path
     */
    public function setClientCertificatePath($path)
    {
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
}
