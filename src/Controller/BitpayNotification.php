<?php
/**
 * @file
 * Contains \Drupal\uc_bitpay\Controller.
 */

namespace Drupal\uc_bitpay\Controller;
use Drupal\uc_order\Entity\Order;
use Drupal\Core\Controller\ControllerBase;
use Drupal\uc_payment\Entity\PaymentMethod;


class BitpayNotification extends ControllerBase
{
    public function content()
    {
        $checkerr = 'authentication failed';
        $method   = PaymentMethod::load('bitpay');
        $plugin   = $method->getPlugin();
        $config   = $plugin->getConfiguration();
        $notify   = bpVerifyNotification($config['admin']['uc_bitpay_current_api_key']);
        $to       = $config['admin']['uc_bitpay_alert_email'];
        
        if (is_string($notify)) {
            // we have an error; check to see if it's because of a bad API key
            if (substr($notify, 0, strlen($checkerr)) == $checkerr) {
                // try our prior API key, on the off chance we changed API keys
                // while one or more invoices were still active
                $notify = bpVerifyNotification($config['admin']['uc_bitpay_current_api_key']);
            }
        }
        
        // if we received a string instead of an array, we have an error
        if (is_string($notify)) {
            // if it's due to a bad API key, alert the notification email address
            if (substr($notify, 0, strlen($checkerr)) == $checkerr) {
                // send the email
                $to      = $config['admin']['uc_bitpay_alert_email'];
                $success = \Drupal::service('plugin.manager.mail')->mail('uc_bitpay', 'invalid_api_key', $to, $language, array(), "BitPay");
            }
            
            
        }
        
        // get the order ID from our database;
        // if none found, nothing we can do with it
        $order_id = db_query("SELECT order_id FROM {uc_payment_bitpay} WHERE invoice_id = :invoice_id", array(
            ':invoice_id' => $notify['id']
        ))->fetchField();
        if (($order_id === false) || !is_numeric($order_id) || ($order_id == 0)) {
            
        }
        $order        = Order::load($order_id);
        // pull the order status and user ID from the database
        $order_status = $order->getStatusId();
        $uid          = $order->getOwnerId();
        \Drupal::logger('uc_bitpay')->notice($order->id() . '-' . $notify['status'] . '--' . $order_status);
        // on certain invoice status changes, do certain things
        switch ($notify['status']) {
            // PAID: Normally this would reflect the fact that the
            // invoice has been updated from 'new' to 'paid', and the
            // payment address has been sent the full amount requested.
            // This module waits until 'confirmed', 'complete',
            // 'expired' or 'invalid' for any DB updates; it does
            // nothing significant if the invoice is merely 'paid'.
            case 'paid':
                // just save a comment
                uc_order_comment_save($order_id, 0, t("Customer has sent the bitcoin transaction for payment, but it has not confirmed yet."), 'admin');
                
                // if we're copying notification emails, here's where we do one
                if ($config['admin']['uc_bitpay_copy_notify_emails']) {
                    // construct an alert to email
                    $params             = array();
                    $params['id']       = $notify['id'];
                    $params['url']      = $notify['url'];
                    $params['order_id'] = $order_id;
                    // send the email
                    $to                 = $config['admin']['uc_bitpay_alert_email'];
                    $success            = drupal_mail('uc_bitpay', 'paid', $to, $language, $params);
                }
                
                break;
            
            // CONFIRMED: Update the DB to reflect the fact that the
            // invoice has been updated to 'confirmed', either from
            // 'new' or from 'paid'. The transaction speed determines
            // how soon 'confirmed' occurs: 'high' will yield 'confirmed'
            // as soon as full payment is made (and will bypass the
            // 'paid' status); 'medium' will yield 'confirmed' after the
            // invoice is 'paid', and the transaction receives one
            // confirmation on the bitcoin blockchain; 'low' will yield
            // 'confirmed' after the invoice is 'paid' and the transaction
            // receives a full six confirmations on the blockchain.
            case 'confirmed':
                // mark the order as Payment received
                $state = $order->getStatusId();
                if (($state != 'canceled') && ($state != 'completed')) {
                    $order->setStatusId('payment_received');
                    $order->save();
                }
                
                // mark the payment
                uc_payment_enter($order_id, 'bitpay', $notify['price'], $uid, NULL, '', REQUEST_TIME);
                
                // note the payment confirmation
                uc_order_comment_save($order_id, 0, t("Customer's bitcoin payment has confirmed according to the transaction speed you have set for Bitpay."), 'admin');
                
                // if we're copying notification emails, here's where we do one
                if ($config['admin']['uc_bitpay_copy_notify_emails']) {
                    // construct an alert to email
                    $params             = array();
                    $params['id']       = $notify['id'];
                    $params['url']      = $notify['url'];
                    $params['order_id'] = $order_id;
                    // send the email
                    $to                 = $config['admin']['uc_bitpay_alert_email'];
                    $success            = drupal_mail('uc_bitpay', 'confirmed', $to, $language, $params);
                }
                break;
            case 'complete':
                // mark the order as Payment received if it hasn't been already
                
                if ($order_status != 'payment_received') {
                    
                    // mark the payment
                    uc_order_comment_save($order_id, 0, "Bitpay Invoice URL:" . $notify['url'], 'admin');
                    
                    uc_payment_enter($order_id, 'bitpay', $notify['price'], $uid, NULL, '', REQUEST_TIME);
                    $order->setStatusId('payment_received');
                    $order->save();
                }
                // if we're copying notification emails, here's where we do one
                if ($config['admin']['uc_bitpay_copy_notify_emails']) {
                    // construct an alert to email
                    $params             = array();
                    $params['id']       = $notify['id'];
                    $params['url']      = $notify['url'];
                    $params['order_id'] = $order_id;
                    // send the email
                    $to                 = $config['admin']['uc_bitpay_alert_email'];
                    $success            = drupal_mail('uc_bitpay', 'complete', $to, $language, $params);
                }
                break;
            
            // EXPIRED: This status reflects that the buyer did not submit
            // full payment within the 15-minute window Bitpay allows, and
            // thus the invoice is no longer to be used. As of 2012-10-31,
            // Bitpay does not actively send a notification when an invoice
            // becomes expired. This code will be left in on the chance that
            // they eventually do.
            case 'expired':
                // do nothing
                break;
            
            
            case 'invalid':
                // construct an alert to email
                $params             = array();
                $params['id']       = $notify['id'];
                $params['url']      = $notify['url'];
                $params['order_id'] = $order_id;
                $params['status']   = $notify['status'];
                // send the email
                $to                 = $config['admin']['uc_bitpay_alert_email'];
                $success            = drupal_mail('uc_bitpay', 'invalid', $to, $language);
                
                uc_order_comment_save($order_id, 0, t("The Bitpay invoice for this order has been marked INVALID. You may neet to contact Bitpay to resolve the issue."), 'admin');
                break;
            
            // NEW: This should never be sent as a notification; all invoices
            // are created with this status, and invoices do not revert back to
            // it. If this is still the status, there has been no change and no
            // notification should have been sent.
            //
            // OR
            //
            // OTHER: The invoice has been assigned some unknown, either
            // erroneous or newly-implemented   status.
            //
            // Do nothing except alert the owner of the notification email
            // address of this unusual status notification.
            default:
                // construct an alert to email
                $params             = array();
                $params['id']       = $notify['id'];
                $params['url']      = $notify['url'];
                $params['order_id'] = $order_id;
                $params['status']   = $notify['status'];
                // send the email
                $to                 = $config['admin']['uc_bitpay_alert_email'];
                $success            = \Drupal::service('plugin.manager.mail')->mail('uc_bitpay', 'notice', $to, $language, $params);
        } // end switch - examining the invoice status
        return array(
            '#markup' => '' . t('Bitpay.') . ''
        );
    }
}