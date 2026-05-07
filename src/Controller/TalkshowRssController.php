<?php
/**
 * @file
 * contains \Drupal\custom_example\Controller\TalkshowRssController.
 */

namespace Drupal\custom_example\Controller;

use Drupal\bbsradio_base\Service\BaseHelper;
use Drupal\bbsradio_payment\Controller\PaymentController;
use Drupal\bbsradio_payment\Service\PaymentDataManager;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\custom_example\Service\feedDataManager;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TalkshowRssController extends ControllerBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var AccountInterface $account
   */
  protected $account;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Render service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The feed data manager service
   *
   * @var \Drupal\custom_example\Service\FeedDataManager $feedDataManager
   */
  protected $feedDataManager;

  /**
   * Constructs class.
   *
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_account,
    Connection $database,
    LoggerChannelFactoryInterface $logger,
    MessengerInterface $messenger,
    ConfigFactoryInterface $config_factory,
    RendererInterface $renderer,
    FeedDataManager $feedDataManager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('bbs_feed');
    $this->messenger = $messenger;
    $this->configFactory = $config_factory;
    $this->renderer = $renderer;
    $this->feedDataManager = $feedDataManager;
  }

  /**
   * Creates a new Controller.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   A new Controller object.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('database'),
      $container->get('logger.factory'),
      $container->get('messenger'),
      $container->get('config.factory'),
      $container->get('renderer'),
      $container->get('custom_example.feed_data_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getRSS(NodeInterface $node, Request $request) {
    if (empty($node) || $node == false) {
      throw new AccessDeniedHttpException();
    }

    if ($node->bundle() != 'talk_show_include') {
      throw new AccessDeniedHttpException();
    }

    // Get the data
    $data = $this->feedDataManager->getRSSContent($node, $request);;
    $talk_show_data = $data['talkshow'];
    $archives_data = $data['archives'];
    $last_modified = $data['last_modified'];

    // Render output as XML
    $build = [
      '#theme' => 'talk_show_content_rss',
      '#row' => $talk_show_data,
      '#archives' => $archives_data,
      '#cache' => ['max-age' => 0],
    ];

    $output = $this->renderer->render($build);

    // Response
    $etag = sprintf( '"%s-%s"', $last_modified, md5( $output ) );

    $response = new Response();
    $response->setContent($output);
    $response->headers->set('Content-Type', 'text/xml');
    $response->headers->set('Etag', $etag);
    $response->headers->set('Last-Modified', gmdate( "D, d M Y H:i:s", $last_modified ) . " GMT");
    $response->headers->remove('Pragma');
    $response->headers->remove('Cache-Control');


    // Perform HTTP revalidation.
    // @todo Use Response::isNotModified() as
    //   per https://www.drupal.org/node/2259489.
    $last_modified = $response->getLastModified();
    if ($last_modified) {
      // See if the client has provided the required HTTP headers.
      $if_modified_since = $request->server
        ->has('HTTP_IF_MODIFIED_SINCE') ? strtotime($request->server
        ->get('HTTP_IF_MODIFIED_SINCE')) : FALSE;
      $if_none_match = $request->server
        ->has('HTTP_IF_NONE_MATCH') ? stripslashes($request->server
        ->get('HTTP_IF_NONE_MATCH')) : FALSE;
      if ($if_modified_since && $if_none_match && $if_none_match == $etag && $if_modified_since == $last_modified->getTimestamp()) {
        $response->isNotModified($request);
      }
    }

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function getRecentTalkshowRss(Request $request) {
    // Get the data
    $data = $this->feedDataManager->getRecentTalkshowData($request);;
    $feed_data = $data['feed_info'];
    $talk_show_data = $data['talkshow'];

    // Render output as XML
    $build = [
      '#theme' => 'talk_show_content_recent_rss',
      '#row' => $feed_data,
      '#talkshows' => $talk_show_data,
      '#cache' => ['max-age' => 0],
    ];
    $output = $this->renderer->render($build);

    // Response
    $response = new Response();
    $response->setContent($output);
    $response->headers->set('Content-Type', 'application/rss+xml');

    return $response;
  }


}
