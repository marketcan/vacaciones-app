<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future.If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 * We offer the best and most useful modules PrestaShop and modifications for your online store.
 *
 * @author    knowband.com <support@knowband.com>
 * @copyright 2017 Knowband
 * @license   see file: LICENSE.txt
 * @category  PrestaShop Module
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

//Include Google Module Class to inherit some common functions and callbacks
require_once(_PS_MODULE_DIR_ . 'kbgoogleshopping/classes/KbGSModule.php');
require_once(_PS_MODULE_DIR_ . 'kbgoogleshopping/classes/KbGSAuditLog.php');

class AdminKbGSAuditLogController extends ModuleAdminController
{
    //Class Constructor
    public function __construct()
    {
        $this->context = Context::getContext();
        $this->bootstrap = true;
        $this->table = 'kb_gs_audit_log';
        $this->className = 'KbGSAuditLog';
        $this->identifier = 'id_gs_audit_log';
        parent::__construct();
        $this->toolbar_title = $this->module->l('Audit Log', 'AdminKbGSAuditLogController');
        $this->fields_list = array(
            'id_gs_audit_log' => array(
                'title' => $this->module->l('Log ID', 'AdminKbGSAuditLogController'),
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ),
            'log_entry' => array(
                'title' => $this->module->l('Action Description', 'AdminKbGSAuditLogController'),
                'float' => true,
                
            ),
            'log_user' => array(
                'title' => $this->module->l('Action User', 'AdminKbGSAuditLogController'),
                'align' => 'center'
            ),
            'log_class_method' => array(
                'title' => $this->module->l('Action Called', 'AdminKbGSAuditLogController')
            ),
            'log_time' => array(
                'title' => $this->module->l('Time of Action', 'AdminKbGSAuditLogController'),
                'type' => 'datetime'
            )
        );
        
        $this->_orderBy = 'id_gs_audit_log';
        $this->_orderWay = 'DESC';

        $this->list_no_link = true;
        parent::__construct();
    }

    public function initToolbar()
    {
        parent::initToolbar();
        unset($this->toolbar_btn['new']);
    }
}
