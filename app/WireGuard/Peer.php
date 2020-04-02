<?php

namespace App\WireGuard;

class Peer
{
    private $wg;
    private $interface;

    public $ip;
    public $pubkey;
    public $privkey;
    public $psk;
    public $online;

    /**
     * Adapter constructor.
     * @param Service $wg
     * @param Adapter $interface
     * @param bool $gen_keys
     * @throws Exception
     */
    public function __construct(Service $wg, Adapter $interface, bool $gen_keys = true)
    {
        $this->wg = $wg;
        $this->interface = $interface;

        if ($gen_keys) {
            $this->privkey = $wg->genPrivKey() ;
            $this->pubkey = $wg->genPubKey($this->privkey);
            $this->psk = $wg->genPsk();
        }

        $this->online = false;
    }

    /**
     * @return bool
     * @throws Exception
     */
    protected function checkReadyState(): bool
    {
        if (!$this->interface instanceof Adapter) {
            throw new Exception('Invalid adapter bind.');
        }

        if (empty($this->ip)) {
            throw new Exception('Missed IP address.');
        }

        if (!filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new Exception("Invalid IP address.");
        }

        if (!System::ip_in_range($this->ip, $this->interface->ip)) {
            throw new Exception("IP address is not in parent interface network.");
        }

        if (empty($this->privkey)) {
            throw new Exception('Missed private key.');
        }

        if (empty($this->pubkey)) {
            throw new Exception('Missed public key.');
        }

        if (empty($this->psk)) {
            throw new Exception('Missed preshared key.');
        }

        return true;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getTemplate(): string
    {
        return <<<EOF
        [Interface]
        PrivateKey = {$this->privkey}
        Address    = {$this->ip}/32
        DNS        = 8.8.8.8

        [Peer]
        PublicKey    = {$this->interface->pubkey}
        PresharedKey = {$this->psk}
        AllowedIPs   = {$this->interface->subnets}
        Endpoint     = {$this->interface->getEndPoint()}
        PersistentKeepalive = 25
        EOF;
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function save()
    {
        $this->checkReadyState();

        foreach (Peer::ReadAll($this->interface) as $peer) {
            if ($peer->ip == $this->ip || $peer->ip == "{$this->ip}/32") {
                throw new Exception("Peer with IP {$this->ip} already exists.");
            }

            if ($peer->pubkey == $this->pubkey) {
                throw new Exception("Peer {$this->pubkey} already exists.");
            }
        }

        $template = $this->getTemplate();
        System::shot("sudo bash -c 'wg set {$this->interface->name} peer {$this->pubkey} preshared-key <(echo {$this->psk}) allowed-ips {$this->ip}/32'");
        System::shot("sudo mkdir -p /etc/wireguard/clients/{$this->interface->name}");
        System::shot("echo '{$template}' | sudo tee '/etc/wireguard/clients/{$this->interface->name}/{$this->ip}.conf'");

        return true;
    }

    public function delete(): bool
    {
        System::shot("sudo wg set {$this->interface->name} peer {$this->pubkey} remove");
        return true;
    }

    /**
     * @param Adapter $interface
     * @param array $data
     * @return Peer
     * @throws Exception
     */
    public static function Create(Adapter $interface, array $data): Peer
    {
        $ip = strval($data['ip'] ?? null);
        $peer = new Peer(app()->get(Service::class), $interface);
        $peer->ip = $ip;

        return $peer;
    }

    /**
     * @param Adapter $interface
     * @param string $search
     * @return Peer
     * @throws Exception
     */
    public static function Read(Adapter $interface, string $search): ?Peer
    {
        $peers = self::ReadAll($interface);
        foreach ($peers as $peer) {
            if ($peer->ip == $search || $peer->ip == "{$search}/32") {
                return $peer;
            } elseif($peer->pubkey == $search) {
                return $peer;
            }
        }

        throw new Exception("Could not find peer '{$search}'");
    }

    /**
     * @param Adapter $interface
     * @return array
     * @throws Exception
     */
    public static function ReadAll(Adapter $interface): array
    {
        $peers = [];

        $key_ip = System::exec("sudo wg show {$interface->name} allowed-ips");
        foreach ($key_ip as $line) {
            list($pubkey, $ip) = explode("\t", $line);

            $peer = new Peer(app()->get(Service::class), $interface, false);
            $peer->pubkey = $pubkey;
            $peer->ip = $ip;
            $peers[$pubkey] = $peer;
        }

        $key_psk = System::exec("sudo wg show {$interface->name} preshared-keys");
        foreach ($key_psk as $line) {
            list($pubkey, $psk) = explode("\t", $line);
            if (isset($peers[$pubkey])) {
                $peers[$pubkey]->psk = $psk;
            }
        }

        return array_values($peers);
    }
}
