<?php

namespace SetBased\Abc\Mail\Command;

use Psr\Log\LoggerInterface;
use SetBased\Abc\Abc;
use SetBased\Abc\C;
use SetBased\Exception\FallenException;
use SetBased\Exception\RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * Abstract command for sending mail messages.
 */
abstract class MailerCommand extends Command
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * If true this command will terminate.
   *
   * @var bool
   */
  static protected $terminate = false;

  /**
   * The logger.
   *
   * @var LoggerInterface
   */
  protected $logger;

  /**
   * Array with domains for which we are authorized to send email.
   *
   * @var array
   */
  private $domains;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Gets the list of domains for which we are authorized to send emails.
   */
  public function getAuthorizedDomains(): void
  {
    $domains = Abc::$DL->abcMailBackGetAuthorizedDomains();

    foreach ($domains as $domain)
    {
      $this->domains[mb_strtolower($domain['atd_domain_name'])] = true;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Connects to the MySQL instance.
   *
   * @return void
   *
   * @api
   * @since 1.0.0
   */
  abstract protected function connect(): void;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * This mailer sends mail messages for all companies in the databases. Changes the company to the company of the
   * mail message currently being send.
   *
   * @param int $cmpId The ID of the new company.
   *
   * @return void
   *
   * @api
   * @since 1.0.0
   */
  abstract protected function changeCompany(int $cmpId): void;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Disconnects from MySQL instance.
   *
   * @api
   * @since 1.0.0
   */
  protected function disconnect(): void
  {
    Abc::$DL->disconnect();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sends a batch of mail messages.
   */
  protected function sendBatch(): void
  {
    $this->connect();
    Abc::$DL->begin();

    $this->getAuthorizedDomains();

    do
    {
      $messages = Abc::$DL->abcMailBackGetUnsentMessages();
      foreach ($messages as $message)
      {
        $this->sendMail($message);

        if (self::$terminate) break;
      }
    } while (count($messages)==C::ABC_MAIL_BACK_BATCH_SIZE && !self::$terminate);

    $this->disconnect();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the message body.
   *
   * @param \PHPMailer $mailer  The PHPMailer object.
   * @param array      $message The details of the mail message.
   *
   * @api
   * @since 1.0.0
   */
  protected function setBody(\PHPMailer $mailer, array $message): void
  {
    $blob = Abc::$abc->getBlobStore()->getBlob($message['blb_id_body']);

    preg_match('/^([^;]*);\s*charset=(.*)$/', $blob['blb_mime_type'], $matches);
    if (sizeof($matches)!=3)
    {
      throw new RuntimeException("Invalid mime type '%s'", $blob['blb_mime_type']);
    }
    $type    = trim($matches[1]);
    $charset = trim($matches[2]);

    $mailer->isHTML(($type=='text/html'));
    $mailer->CharSet = $charset;
    $mailer->Body    = $blob['blb_data'];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the transmitter of this mail message when the transmitter's address is from an unauthorized domain.
   *
   * @param \PHPMailer $mailer  The PHPMailer object.
   * @param array      $message The details of the mail message.
   *
   * @return void
   *
   * @api
   * @since 1.0.0
   */
  abstract protected function setUnauthorizedFrom(\PHPMailer $mailer, array $message): void;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Adds all headers of a mail message to a PHPMailer object.
   *
   * @param \PHPMailer $mailer  The PHPMailer object.
   * @param array      $message The details of the mail message.
   */
  private function addHeaders(\PHPMailer $mailer, array $message): void
  {
    $headers = Abc::$DL->abcMailBackMessageGetHeaders($message['cmp_id'], $message['elm_id']);

    $replyTo = false;
    foreach ($headers as $header)
    {
      switch ($header['ehd_id'])
      {
        case C::EHD_ID_ATTACHMENT:
          $blob = Abc::$abc->getBlobStore()->getBlob($header['blb_id']);
          $mailer->addStringAttachment($blob['blb_data'], $blob['blb_filename']);
          break;

        case C::EHD_ID_BCC:
          $mailer->addBCC($header['emh_address'], $header['emh_name']);
          break;

        case C::EHD_ID_CC:
          $mailer->addCC($header['emh_address'], $header['emh_name']);
          break;

        case C::EHD_ID_CONFIRM_READING_TO:
          $mailer->ConfirmReadingTo = $header['emh_address'];
          break;

        case C::EHD_ID_CUSTOM_HEADER:
          $mailer->addCustomHeader($header['emh_value']);
          break;

        case C::EHD_ID_MESSAGE_ID:
          $mailer->MessageID = $header['emh_value'];
          break;

        case C::EHD_ID_REPLY_TO:
          $mailer->addReplyTo($header['emh_address'], $header['emh_name']);
          $replyTo = true;
          break;

        case C::EHD_ID_SENDER:
          $mailer->Sender = $header['emh_address'];
          break;

        case C::EHD_ID_TO:
          $mailer->addAddress($header['emh_address'], $header['emh_name']);
          break;

        default:
          throw new FallenException('ehd_id', $header['ehd_id']);
      }

      // Implicitly add ReplyTo header if not set explicitly.
      if (!$replyTo)
      {
        $mailer->addReplyTo($message['elm_address'],  $message['elm_name']);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sends actually the email message.
   *
   * @param array $message The details of the mail message.
   */
  private function sendMail(array $message): void
  {
    try
    {
      $this->logger->notice(sprintf('Sending message elm_id=%d', $message['elm_id']));

      $this->changeCompany($message['cmp_id']);

      Abc::$DL->abcMailBackMessageMarkAsPickedUp($message['cmp_id'], $message['elm_id']);
      Abc::$DL->commit();

      if ($message['elm_number_from']!=1)
      {
        throw new RuntimeException('PHPMailer does not support multiple from addresses');
      }

      $mailer = new \PHPMailer();
      $mailer->isSendmail();
      $mailer->Subject = $message['elm_subject'];

      $this->setBody($mailer, $message);
      $this->setFrom($mailer, $message);
      $this->addHeaders($mailer, $message);

      $success = $mailer->send();
      if ($success)
      {
        Abc::$DL->abcMailBackMessageMarkAsSent($message['cmp_id'], $message['elm_id']);
        Abc::$DL->commit();
      }
      else
      {
        $this->logger->error(sprintf('Sending message elm_id=%d failed: %s', $message['elm_id'], $mailer->ErrorInfo));
      }
    }
    catch (\Exception $exception)
    {
      $this->logger->critical($exception);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the transmitter of this mail message.
   *
   * @param \PHPMailer $mailer  The PHPMailer object.
   * @param array      $message The details of the mail message.
   */
  private function setFrom(\PHPMailer $mailer, array $message): void
  {
    $domain = mb_strtolower(substr($message['elm_address'], strpos($message['elm_address'], '@') + 1));
    if (isset($this->domains[$domain]))
    {
      // We are authorized to send mail messages from this domain.
      $mailer->From     = $message['elm_address'];
      $mailer->FromName = $message['elm_name'];
    }
    else
    {
      // We are not authorized to send mail messages from this domain.
      $this->setUnauthorizedFrom($mailer, $message);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
