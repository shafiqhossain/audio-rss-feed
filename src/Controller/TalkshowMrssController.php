<?php
/**
 * @file
 * contains \Drupal\custom_example\Controller\TalkshowMrssController.
 */

namespace Drupal\custom_example\Controller;

use Drupal\bbsradio_base\Service\BaseHelper;
use Drupal\bbsradio_payment\Controller\PaymentController;
use Drupal\bbsradio_payment\Service\PaymentDataManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\Html;
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

class TalkshowMrssController extends ControllerBase {

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
   * The base helper service
   *
   * @var \Drupal\bbsradio_base\Service\BaseHelper $baseHelper
   */
  protected $baseHelper;

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
    FeedDataManager $feedDataManager,
    BaseHelper $base_helper
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('bbs_feed');
    $this->messenger = $messenger;
    $this->configFactory = $config_factory;
    $this->renderer = $renderer;
    $this->feedDataManager = $feedDataManager;
    $this->baseHelper = $base_helper;
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
      $container->get('custom_example.feed_data_manager'),
      $container->get('base.helper')
    );
  }


  /**
   * {@inheritdoc}
   */
  public function getMRSS(NodeInterface $node, Request $request) {
    if (empty($node) || $node == false) {
      throw new AccessDeniedHttpException();
    }

    if ($node->bundle() != 'talk_show_include') {
      throw new AccessDeniedHttpException();
    }

    $site_url = \Drupal::request()->getSchemeAndHttpHost();
    $theme = \Drupal::theme()->getActiveTheme();

    $talk_show_data = [];
    $talk_show_data['nid'] = $node->id();
    $talk_show_data['link'] = $site_url . '/';
    $talk_show_data['atom_link'] = $site_url . '/customshow/mrss/' . $node->id();

    // Title
    if ($node->hasField('field_feed_talkshow_title') && !$node->get('field_feed_talkshow_title')->isEmpty()) {
      $talk_show_data['title'] = Html::escape($node->get('field_feed_talkshow_title')->value);
    }
    else {
      $talk_show_data['title'] = 'Talkshow MRSS';
    }

    // Description
    if ($node->hasField('field_feed_description') && !$node->get('field_feed_description')->isEmpty()) {
      $talk_show_data['desc'] = $this->baseHelper->xmlEntityReplace($node->get('field_feed_description')->value);
    }
    else {
      $talk_show_data['desc'] = 'Talkshow MRSS';
    }

    // Talk show Banner
    if ($node->hasField('field_include_banner') && !$node->get('field_include_banner')->isEmpty() && $node->get('field_include_banner')->entity) {
      $talk_show_data['image']['url'] = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_include_banner')->entity->getFileUri());
      $talk_show_data['image']['title'] = $node->label();
      $talk_show_data['image']['link'] = $site_url . '/node/' . $node->id();
    }

    // Copyright
    if ($node->hasField('field_include_copyright') && !$node->get('field_include_copyright')->isEmpty()) {
      $talk_show_data['copyright'] = $node->get('field_include_copyright')->value;
    }
    else {
      $talk_show_data['copyright'] = '©2005-' . date('Y') . ' BBS Network, Inc.';
    }

    // Webmaster and Editor and Language
    $talk_show_data['webmaster'] = 'doug@bbsradio.com (Douglas Newsom)';
    $talk_show_data['editor'] = 'doug@bbsradio.com (Douglas Newsom)';
    $talk_show_data['language'] = 'en-us';

    $talk_show_data['pub_date'] = date('D, d M Y H:i:s T', $node->getCreatedTime());
    $talk_show_data['publish_date'] = date('c', $node->getCreatedTime());
    $talk_show_data['up_date'] = date('D, d M Y H:i:s T', $node->getChangedTime());
    $talk_show_data['update_date'] = date('c', $node->getChangedTime());

    // Category and Parent Categories. Only two level of categories are counting
    $categories = [];
    $category_list = '';
    if ($node->hasField('field_include_itunes_categories') && !$node->get('field_include_itunes_categories')->isEmpty() && $node->get('field_include_itunes_categories')->entity) {
      $terms = $node->get('field_include_itunes_categories')->referencedEntities();
      if ($terms && count($terms)>0) {
        foreach ($terms as $term) {
          $parent_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadParents($term->id());
          if ($parent_terms) {
            foreach ($parent_terms as $parent_term) {
              $categories[$parent_term->id()]['tid'] = $parent_term->id();
              $categories[$parent_term->id()]['name'] = $parent_term->getName();
              $categories[$parent_term->id()]['children'][] = [
                'tid' => $term->id(),
                'name' => $term->getName(),
              ];
            }
          }
          else {
            $categories[$term->id()]['tid'] = $term->id();
            $categories[$term->id()]['name'] = $term->getName();
            $categories[$term->id()]['children'] = [];
          }
        }
      }

      if ($categories) {
        foreach ($categories as $category) {
          if (!empty($category_list)) {
            $category_list .= ', ';
          }
          $category_list .= $category['name'];

          if (isset($category['children']) && count($category['children'])) {
            foreach ($category['children'] as $child) {
              if (!empty($category_list)) {
                $category_list .= ', ';
              }
              $category_list .= $child['name'];
            }
          }
        }
      }

      $talk_show_data['categories'] = $categories;
      $talk_show_data['category_list'] = $this->baseHelper->xmlEntityReplace($category_list);
    }

    // Feed Image
    if ($node->hasField('field_include_feed_picture') && !$node->get('field_include_feed_picture')->isEmpty() && $node->get('field_include_feed_picture')->entity) {
      $talk_show_data['feed_image'] = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_include_feed_picture')->entity->getFileUri());
    }
    else {
      $talk_show_data['feed_image'] = '/'. $theme->getPath() . '/images/a-fireside-chat-pod-pic.jpg';
    }

    // Sub-Title
    if ($node->hasField('field_include_feed_subtitle') && !$node->get('field_include_feed_subtitle')->isEmpty()) {
      $subtitle = strip_tags($node->get('field_include_feed_subtitle')->value);
      $subtitle = str_ireplace('&', 'and', $subtitle);
      $subtitle = Html::escape($subtitle);
      $talk_show_data['itunes_subtitle'] = $subtitle;
    }
    else {
      $talk_show_data['itunes_subtitle'] = $node->label();
    }

    // Tags, Keywords
    $tags = [];
    $tag_list = '';
    if ($node->hasField('field_include_feed_tags_keywords') && !$node->get('field_include_feed_tags_keywords')->isEmpty()) {
      $tag_terms = $node->get('field_include_feed_tags_keywords')->referencedEntities();

      foreach ($tag_terms as $tag_term) {
        // Limit tag length to max 255
        $tag_length = strlen($tag_list) + strlen($tag_term->getName());
        if ($tag_length > 253) {
          break;
        }

        if (!empty($tag_list)) {
          $tag_list .= ', ';
        }
        $tag_list .= $tag_term->getName();
        $tags[] = $tag_term->getName();
      }

      $talk_show_data['tag_list'] = $tag_list;
      $talk_show_data['tags'] = $tags;
    }
    else {
      $talk_show_data['tags'][] = 'Keywords';
      $talk_show_data['tag_list'] = 'Keywords';
    }

    // Explicit Materials
    if ($node->hasField('field_include_explicit_materials') && !$node->get('field_include_explicit_materials')->isEmpty()) {
      $talk_show_data['explicit'] = $node->get('field_include_explicit_materials')->value;
    }
    else {
      $talk_show_data['explicit'] = 'clean';
    }

    $talk_show_data['episodic'] = 'episodic';

    // Get the Archives
    $archives_data = $this->feedDataManager->getMrssArchives($node->id());

    $build = [
      '#theme' => 'talk_show_content_mrss',
      '#row' => $talk_show_data,
      '#archives' => $archives_data,
      '#cache' => ['max-age' => 0],
    ];
    $output = $this->renderer->render($build);

    // Correction
    $output = str_replace('&amp;', '&', $output);
    $output = str_replace('&#039;', "'", $output);
    $output = str_replace('&quot;', '"', $output);

    $response = new Response();
    $response->setContent($output);
    $response->headers->set('Content-Type', 'application/rss+xml');

    return $response;
  }

}
