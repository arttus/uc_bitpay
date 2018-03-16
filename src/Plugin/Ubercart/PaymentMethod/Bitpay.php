<?php


namespace Drupal\uc_bitpay\Plugin\Ubercart\PaymentMethod;

use Drupal\Core\Form\FormStateInterface;
use Drupal\uc_payment\PaymentMethodPluginBase;
use Drupal\uc_order\OrderInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
/**
 * Defines the Bitcoin payment method.
 *
 * This is a dummy payment gateway to use for testing or as an example. All
 * payments using this test gateway will succeed, except when one of the
 * following is TRUE:
 * - Credit card number equals '0000000000000000'. (Note that ANY card number
 *   that fails the Luhn algorithm check performed by uc_credit will not even be
 *   submitted to this gateway).
 * - CVV equals '000'.
 * - Credit card is expired.
 * - Payment amount equals 12.34 in store currency units.
 * - Customer's billing first name equals 'Fictitious'.
 * - Customer's billing telephone number equals '8675309'.
 *
 * @UbercartPaymentMethod(
 *   id = "bitpay",
 *   name = @Translation("Bitpay"),
 * )
 */
class Bitpay extends PaymentMethodPluginBase
{
    
    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration()
    {
        return parent::defaultConfiguration() + array(
            'debug' => FALSE
        );
    }
    public function getDisplayLabel($label)
    {
        
        $build['label'] = array(
            '#plain_text' => $label
        );
        $build['image'] = array(
            '#theme' => 'image',
            '#uri' => drupal_get_path('module', 'uc_bitpay') . '/images/bitcoin.png',
            '#alt' => 'Bitcoin'
        );
        
        return $build;
    }
    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        global $base_url;
        $form = parent::buildConfigurationForm($form, $form_state);
        
        
        $form['admin']                                  = array(
            '#type' => 'fieldset',
            '#title' => t('Administrator settings'),
            '#collapsible' => TRUE,
            '#collapsed' => TRUE
        );
        $form['admin']['uc_bitpay_current_api_key']     = array(
            '#type' => 'textfield',
            '#title' => t('Current Bitpay API key'),
            '#default_value' => $this->configuration['admin']['uc_bitpay_current_api_key'],
            '#description' => t('Your current Bitpay API key should be entered here. This key must be kept private. To view or edit your API keys, please go to your merchant account at') . ' <a href="' . BITPAY_WEBSITE . '" target="_blank">' . t("Bitpay's website") . '</a>.'
        );
        $form['admin']['uc_bitpay_prior_api_key']       = array(
            '#type' => 'item',
            '#title' => t('Prior Bitpay API key'),
            '#description' => t('This is retained on the chance that you change API keys while') . ' ' . t('Bitpay invoices are still pending. To clear, change the current') . ' ' . t('API key to a random number and save the changes, then re-enter') . ' ' . t('the current API key again and save the changes again.'),
            '#value' => "'<i>" . $this->configuration['admin']['uc_bitpay_prior_api_key'] . "</i>'"
        );
        $form['admin']['uc_bitpay_notify_email']        = array(
            '#type' => 'textfield',
            '#title' => t('Notification email address'),
            '#default_value' => $this->configuration['admin']['uc_bitpay_notify_email'],
            '#description' => t('Email address to receive Bitpay invoice notifications. Primarily for debugging or to be informed of any bitcoin payments. Please use all lowercase when entering the email address.')
        );
        $form['admin']['uc_bitpay_notify_email_active'] = array(
            '#type' => 'checkbox',
            '#title' => t('Allow Bitpay invoice notifications to be emailed.'),
            '#default_value' => $this->configuration['admin']['uc_bitpay_notify_email_active']
        );
        $form['admin']['uc_bitpay_alert_email']         = array(
            '#type' => 'textfield',
            '#title' => t('Alert email address'),
            '#default_value' => $this->configuration['admin']['uc_bitpay_alert_email'],
            '#description' => t('Email address to receive alerts direct from the Bitpay module. The most appropriate recipient would be your website developer.')
        );
        $form['admin']['uc_bitpay_copy_notify_emails']  = array(
            '#type' => 'checkbox',
            '#title' => t('Send a message to the alert email whenever Bitpay sends an invoice notification.'),
            '#default_value' => $this->configuration['admin']['uc_bitpay_copy_notify_emails']
        );
        $form['admin']['uc_bitpay_full_notify']         = array(
            '#type' => 'radios',
            '#title' => t('Full notification?'),
            '#options' => array(
                0 => t('NO, only send notifications on a status upgrade to "confirmed."'),
                1 => t('YES, send notifications any time an invoice status changes.')
            ),
            '#default_value' => $this->configuration['admin']['uc_bitpay_full_notify'],
            '#description' => t('Whether you want to receive notifications for every status change for every Bitpay invoice. It is safe to choose NO, but if you wish for more updates you should choose YES.')
        );
        $form['admin']['uc_bitpay_base_url']            = array(
            '#type' => 'textfield',
            '#title' => t('Store website base URL'),
            '#default_value' => $this->configuration['admin']['uc_bitpay_base_url'],
            '#description' => t("Base URL of the store website. It is presented here primarily as an option to force the use of the 'https' version of your website if it doesn't automatically use it. Bitpay") . ' ' . '<b>' . t('must') . '</b>' . ' ' . t("use 'https', so please add the 's' if needed. For reference, the store's internal base URL is normally:") . ' ' . '<b>' . $base_url . '</b>'
        );
        
        $form['general']                           = array(
            '#type' => 'fieldset',
            '#title' => t('General settings'),
            '#collapsible' => FALSE,
            '#collapsed' => FALSE
        );
        $form['general']['uc_bitpay_redirect_url'] = array(
            '#type' => 'textfield',
            '#title' => t('Redirect URL'),
            '#default_value' => $this->configuration['general']['uc_bitpay_redirect_url'],
            '#description' => t('URL to redirect buyers to after a Bitpay purchase. Not necessary since the payment feature appears inside the checkout order review, but usually set to the store URL (or the user cart) just in case the buyer clicks it.')
        );
        $form['general']['uc_bitpay_currency']     = array(
            '#type' => 'select',
            '#title' => t('Store currency'),
            '#options' => _uc_bitpay_currency_array(),
            '#default_value' => $this->configuration['general']['uc_bitpay_currency'],
            '#description' => t('The currency the store sets prices in. These prices are automatically converted to the current bitcoin price by Bitpay. Merchants will receive the full value of the purchase (minus Bitpay fees) without risk of cross-currency price volatility.')
        );
        $form['general']['uc_bitpay_physical']     = array(
            '#type' => 'radios',
            '#title' => t('Physical items?'),
            '#options' => array(
                0 => t('NO, this store primarily sells services or virtual goods.'),
                1 => t('YES, this store generally sells physical goods.')
            ),
            '#default_value' => $this->configuration['general']['uc_bitpay_physical'],
            '#description' => t('Whether, in general, purchases made with bitcoin on your website will involve the sale of a physical good.')
        );
        $form['general']['uc_bitpay_fee_type']     = array(
            '#type' => 'select',
            '#title' => t('Bitcoin handling fee type'),
            '#options' => array(
                'percentage' => t('Percentage') . ' (%)',
                'multiplier' => t('Multiplier') . ' (x)',
                'addition' => t('Addition') . ' (' . \Drupal::state()->get('uc_currency_sign') . ')'
            ),
            '#default_value' => $this->configuration['general']['uc_bitpay_fee_type'],
            '#description' => t('The type of bitcoin handling fee to add to the final price. This can be Percentage, a Multiplier, or a flat-amount Addition.')
        );
        $form['general']['uc_bitpay_fee_amt']      = array(
            '#type' => 'textfield',
            '#title' => t('Bitcoin handling fee amount'),
            '#default_value' => $this->configuration['general']['uc_bitpay_fee_amt'],
            '#description' => t('The actual amount of the percent, multiplier or addition to be added to each bitcoin purchase. NOTE: If you want the customer to cover a Bitpay fee of 3.99%, 2.69% or 0.99%, you should charge a handling fee of 4.16%, 2.77% or 1%, respectively (this assumes no other fees or extra line items will be collected. Adjust accordingly.)')
        );
        $form['general']['uc_bitpay_txn_speed']    = array(
            '#type' => 'radios',
            '#title' => t('Transaction speed'),
            '#options' => array(
                'low' => t('LOW: fully secure, ~1 hour to confirm'),
                'medium' => t('MEDIUM: very safe, ~10 minutes to confirm'),
                'high' => t('HIGH: reasonably safe for small purchases, instant confirmation (please see warning below)')
            ),
            
            '#default_value' => $this->configuration['general']['uc_bitpay_txn_speed'],
            '#description' => t('Speed at which the bitcoin transaction registers as "confirmed" to the store. This overrides your merchant settings on the Bitpay website.') . ' ' . '<b>' . t('WARNING:') . ' ' . '</b>' . t('High and medium-speed transactions allow the slight possibility of a fraudulent double-spend. Fraudulent medium-speed transactions are extremely unlikely, and require enormous amounts of computing power. Fraudulent high-speed transactions are unlikely, and require some degree of technical effort to achieve. IT IS STRONGLY RECOMMENDED THAT HIGH-SPEED TRANSACTIONS BE AVOIDED WHEN POSSIBLE, AND THAT THEY ONLY INVOLVE SMALL PURCHASE AMOUNTS.') . ' ' . '<b>' . t('IF YOU ARE UNSURE OF WHAT SPEED TO USE, PLEASE USE THE "LOW" TRANSACTION SPEED.') . '</b>'
        );
        
        $form['discount_type']    = array(
            '#type' => 'radios',
            '#title' => $this->t('Discount Type'),
            '#default_value' => $this->configuration['discount_type'],
            '#options' => array(
                '_none' => 'No discount',
                'percent_discount' => '% Discount',
                'fixed_discount' => 'Fixed Discount'
            )
        );
        $form['percent_discount'] = array(
            '#type' => 'textfield',
            '#title' => $this->t('% Discount'),
            '#default_value' => $this->configuration['percent_discount'],
            '#states' => array(
                'visible' => array( // action to take.
                    ':input[name="settings[discount_type]"]' => array(
                        'value' => 'percent_discount'
                    )
                )
            )
        );
        $form['fixed_discount']   = array(
            '#type' => 'textfield',
            '#title' => $this->t('Fixed Discount'),
            '#default_value' => $this->configuration['fixed_discount'],
            '#states' => array(
                'visible' => array( // action to take.
                    ':input[name="settings[discount_type]"]' => array(
                        'value' => 'fixed_discount'
                    )
                )
            )
        );
        return $form;
    }
    
    public function getDiscountedPrice($order)
    {
        $discount_type    = $this->configuration['discount_type'];
        $total            = $order->getTotal();
        $discounted_price = $total;
        if ($discount_type == "percent_discount") {
            return ($total * ($this->configuration['percent_discount'] / 100));
        } else if ($discount_type == "fixed_discount") {
            return $this->configuration['percent_discount'];
        }
        return $discounted_price;
    }
    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        $this->configuration['debug'] = $form_state->getValue('debug');
        return parent::submitConfigurationForm($form, $form_state);
    }
    
    /**
     * {@inheritdoc}
     */
    public function cartDetails(OrderInterface $order, array $form, FormStateInterface $form_state)
    {
        
        $txt = t('When you proceed to review your order, you will be shown a bitcoin address for payment, along with a QR code of the address.') . '<br /><br />' . t('The price will be converted to bitcoins at the current to-the-minute exchange rate, and you will have') . ' ' . BITPAY_INVOICE_EXPIRATION_TIME . ' ' . t('minutes to send payment before the invoice expires.');
        $amt = 0;
        if ($amt > 0) {
            //changed due to uc_price() removal in Drupal 7.x
            $sign    = 'BTC';
            $after   = false;
            $dec     = '.';
            $thou    = ',';
            $formNum = number_format($amt, 2, $dec, $thou);
            if ($after) {
                $txt .= '<br /><br /><b>' . t('Please note that a bitcoin handling fee of') . ' ' . $formNum . $sign . ' ' . t('will be added to the final cost.') . '</b>';
            } else {
                $txt .= '<br /><br /><b>' . t('Please note that a bitcoin handling fee of') . ' ' . $sign . $formNum . ' ' . t('will be added to the final cost.') . '</b>';
            }
        }
        $details = $txt;
        
        
        $build['instructions'] = array(
            '#markup' => $txt
        );
        
        
        return $build;
    }
    
    /**
     * {@inheritdoc}
     */
    public function cartReview(OrderInterface $order)
    {
        $need_new_invoice = FALSE;
        $result           = db_query("SELECT invoice_id FROM {uc_payment_bitpay} WHERE order_id = :order_id", array(
            ':order_id' => $order->id()
        ))->fetchField();
        // if no valid invoice is found, we need a new one
        if (($result === FALSE) || (!is_string($result))) {
            
            $need_new_invoice = TRUE;
        } else {
            
            $resp = bpGetInvoice($result, $this->configuration['admin']['uc_bitpay_current_api_key']);
            
            if (is_array($resp)) {
                
                // check to see if the invoice is expired,
                // or price or currency have changed
                $text_total = $order->getTotal();
                if (($resp['status'] == 'expired') || ($resp['price'] != round($text_total, 2)) || ($resp['currency'] != $this->configuration['general']['uc_bitpay_currency'])) {
                    
                    // we need a new one
                    $need_new_invoice = TRUE;
                }
            } else {
                
                // if we couldn't get the invoice, assume we need one
                $need_new_invoice = TRUE;
            }
        }
        
        
        if ($need_new_invoice) {
           
            db_delete('uc_payment_bitpay')->condition('order_id', $order->id())->execute();
            
            $resp = uc_bitpay_create_invoice($order);
            if (is_array($resp) && isset($resp['id'])) {
                
                if ($this->configuration['admin']['uc_bitpay_notify_email_active']) {
                    $notify_email = $this->configuration['admin']['uc_bitpay_notify_email'];
                    
                } else {
                    $notify_email = '';
                }
                $txn_speed = $this->configuration['general']['uc_bitpay_txn_speed'];
                $physical  = $this->configuration['general']['uc_bitpay_physical'];
                $id        = db_insert('uc_payment_bitpay')->fields(array(
                    'invoice_id' => $resp['id'],
                    'order_id' => $order->id(),
                    'notify_email' => $notify_email,
                    'physical' => $physical,
                    'txn_speed' => $txn_speed
                ))->execute();
                
                $success = TRUE;
            } else {
                $success = FALSE;
            }
            
        } else {
          
            $success = TRUE;
            
        }
        
        if ($success) {
            $invoice_id = db_query("SELECT invoice_id FROM {uc_payment_bitpay} WHERE order_id = :order_id", array(
                ':order_id' => $order->id()
            ))->fetchField();
            $resp       = bpGetInvoice($invoice_id, $this->configuration['admin']['uc_bitpay_current_api_key']);
            
            $invoice_url       = $resp['url'];
            $review['#markup'] = '<a href="' . $invoice_url . '" class="btn btn-primary popup-colorbox">Pay</a>';
            if ($resp['status'] == "complete" || $resp['status'] == "paid") {
                
                $session = \Drupal::service('session');
                $session->remove('uc_checkout_review_' . $order->id());
                $session->set('uc_checkout_complete_' . $order->id(), TRUE);
                $response = new \Symfony\Component\HttpFoundation\RedirectResponse('/cart/checkout/complete');
                $response->send();
            } else {
                $response = new \Symfony\Component\HttpFoundation\RedirectResponse($invoice_url);
                $response->send();
            }
            
        } else {
            $review['#markup'] = '<b>Error creating Bitpay invoice</b>';
            
            \Drupal::logger('uc_bitpay')->notice('Error creating Bitpay invoice: ' . $resp['error']['message']);
            bplog("Error creating Bitpay invoice: " . var_export($resp, true));
        }
        $build['#markup'] = $review;
        return $build;
        
    }
    /**
     * {@inheritdoc}
     */
    public function orderSubmit(OrderInterface $order)
    {
        $invoice_id = db_query("SELECT invoice_id FROM {uc_payment_bitpay} WHERE order_id = :order_id", array(
            ':order_id' => $order->id()
        ))->fetchField();
        $resp       = bpGetInvoice($invoice_id, $this->configuration['admin']['uc_bitpay_current_api_key']);
        
        if (($resp['status'] == 'new') || ($resp['status'] == 'expired')) {
            // The invoice is still new or is expired; total payment wasn't made in time.
            $message = 'Full payment was not made on this order. If the invoice has expired and you still wish to make this purchase, please go back and checkout again. If it has expired and you made partial payment, but not full payment, please contact us for a refund or to apply the funds to another order.';
            
            return $this->t($message);
        } else {
            // The invoice was paid and is in some in-payment or complete state.
            // If the status is already 'confirmed', it means they had high
            // transaction speeds
            if ($resp['status'] == 'confirmed') {
                
                $order->setStatusId('payment_received');
                $order->save();
            } else {
                // It's not confirmed yet; show the order status as Bitpay - pending.
                $order->setStatusId('bitpay_pending');
                $order->save();
            }
            return TRUE;
        }
    }
}
