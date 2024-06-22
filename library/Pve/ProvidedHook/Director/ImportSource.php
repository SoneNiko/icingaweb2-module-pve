<?php

namespace Icinga\Module\Pve\ProvidedHook\Director;

use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Pve\Api;


/**
 * Class ImportSource
 *
 * This is where we provide an Import Source for the Icinga Director
 */
class ImportSource extends ImportSourceHook
{
    /** @var  Api */
    protected $api;

    public function getName()
    {
        return 'Proxmox Virtual Environment (PVE)';
    }

    public function fetchData()
    {
        $data = [];

        $api = $this->api();
        $api->login();
        switch ($this->getSetting("object_type")) {
            case "VirtualMachine":
                $fetchGuestAgent = $this->getSetting('vm_guest_agent') === 'y';
                $fetchDescription = $this->getSetting('vm_description') === 'y';
                $fetchHaState = $this->getSetting('vm_ha') === 'y';

                $data = $api->getVMs($fetchGuestAgent, $fetchDescription, $fetchHaState);

                break;
            case "HostSystem":
                $data = $api->getNodes();

                break;
            case "Pools":
                $data = $api->getPools();

                break;
        }
        $api->logout();

        return $data;
    }

    public function listColumns()
    {
        return array_keys((array)current($this->fetchData()));
    }

    /**
     * @inheritdoc
     */
    public static function getDefaultKeyColumnName()
    {
        return 'vm_name';
    }

    public static function addSettingsFormFields(QuickForm $form)
    {
        if (!function_exists('curl_version')) {
            $form->addError($form->translate(
                'The PHP CURL extension (php-curl) is not installed/enabled'
            ));

            return;
        }

        $form->addElement('select', 'object_type', [
            'label' => $form->translate('Object Type'),
            'description' => $form->translate(
                'The managed PVE object type this Import Source should fetch'
            ),
            'multiOptions' => $form->optionalEnum([
                'VirtualMachine' => 'Virtual Machines',
                'HostSystem' => 'Host Systems',
                'Pools' => 'Pools',
            ]),
            'class' => 'autosubmit',
            'required' => true,
        ]);

        $vm = ($form->getSentOrObjectSetting('object_type', 'HostSystem') === 'VirtualMachine');

        if ($vm) {
            static::addBoolean($form, 'vm_guest_agent', [
                'label' => $form->translate('Use Guest Agent'),
                'description' => $form->translate(
                    'Fetch additional data from the QEMU guest agent (additional user permissions needed: VM.Monitor)'
                ),
            ], 'n');

            static::addBoolean($form, 'vm_ha', [
                'label' => $form->translate('Fetch VM HA state'),
                'description' => $form->translate(
                    'Fetch VM HA state. This will result in an additional query for each VM, thus can be slow for larger environments.'
                ),
            ], 'n');

            static::addBoolean($form, 'vm_description', [
                'label' => $form->translate('Fetch VM description'),
                'description' => $form->translate(
                    'Fetch VM description from configuration. This will result in an additional query for each VM, thus can be slow for larger environments.'
                ),
            ], 'n');
        }

        $form->addElement('select', 'scheme', [
            'label' => $form->translate('Protocol'),
            'description' => $form->translate(
                'Whether to use encryption when talking to your PVE cluster'
            ),
            'multiOptions' => [
                'HTTPS' => $form->translate('HTTPS (strongly recommended)'),
                'HTTP' => $form->translate('HTTP (this is plaintext!)'),
            ],
            'class' => 'autosubmit',
            'value' => 'HTTPS',
            'required' => true,
        ]);

        $ssl = !($form->getSentOrObjectSetting('scheme', 'HTTPS') === 'HTTP');

        if ($ssl) {
            static::addBoolean($form, 'ssl_verify_peer', [
                'label' => $form->translate('Verify Peer'),
                'description' => $form->translate(
                    'Whether we should check that our peer\'s certificate has'
                    . ' been signed by a trusted CA. This is strongly recommended.'
                )
            ], 'y');
            static::addBoolean($form, 'ssl_verify_host', array(
                'label' => $form->translate('Verify Host'),
                'description' => $form->translate(
                    'Whether we should check that the certificate matches the'
                    . 'configured host'
                )
            ), 'y');
        }

        $form->addElement('text', 'host', array(
            'label' => $form->translate('PVE host'),
            'description' => $form->translate(
                'In most cases you want to use a fully qualified domain name (and should'
                . ' match it\'s certificate). Alternatively, an IP address is fine.'
                . ' Please use <host>:<port> in case you\'re not using default'
                . ' HTTP(s) ports'
            ),
            'required' => true,
        ))->add;

        $form->addElement('text', 'port', array(
            'label' => $form->translate('PVE port'),
            'description' => $form->translate(
                'Default port is 8006'
            ),
            'placeholder' => '8006',
            'required' => true,
        ));

        $form->addElement('select', 'realm', [
            'label' => $form->translate('Realm'),
            'multiOptions' => [
                'pam' => $form->translate('Linux PAM standard authentication'),
                'pve' => $form->translate('Proxmox VE authentication server'),
            ],
            'class' => 'autosubmit',
            'value' => 'pam',
            'required' => true,
        ]);


        $form->addElement('text', 'username', array(
            'label' => $form->translate('Username'),
            'description' => $form->translate(
                'Will be used for API authentication against your PVE host'
            ),
            'required' => true,
        ));

        $form->addElement('password', 'password', array(
            'label' => $form->translate('Password'),
            'required' => true,
        ));
    }

    protected static function addBoolean($form, $key, $options, $default = null)
    {
        if ($default === null) {
            return $form->addElement('OptionalYesNo', $key, $options);
        } else {
            $form->addElement('YesNo', $key, $options);
            return $form->getElement($key)->setValue($default);
        }
    }

    protected static function optionalBoolean($form, $key, $label, $description)
    {
        return static::addBoolean($form, $key, array(
            'label' => $label,
            'description' => $description
        ));
    }

    protected function api()
    {
        if ($this->api === null) {
            $scheme = $this->getSetting('scheme', 'HTTPS');
            $port = $this->getSetting('port', '8006');

            $this->api = new Api(
                $this->getSetting('host'),
                $port,
                $this->getSetting('realm'),
                $this->getSetting('username'),
                $this->getSetting('password'),
                $scheme
            );

            $curl = $this->api->curl();

            if ($scheme === 'HTTPS') {
                if ($this->getSetting('ssl_verify_peer', 'y') === 'n') {
                    $curl->disableSslPeerVerification();
                }
                if ($this->getSetting('ssl_verify_host', 'y') === 'n') {
                    $curl->disableSslHostVerification();
                }
            }
        }

        return $this->api;
    }
}
