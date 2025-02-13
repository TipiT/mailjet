<?php

namespace Drupal\mailjet;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Link;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Drupal\Core\Menu\MenuLinkManager;

/**
 * Class to define the menu_link breadcrumb builder.
 */
class MailjetBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * The router request context.
   *
   * @var \Drupal\Core\Routing\RequestContext
   */
  protected $context;

  /**
   * The menu link access service.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  protected $accessManager;

  /**
   * The dynamic router service.
   *
   * @var \Symfony\Component\Routing\Matcher\RequestMatcherInterface
   */
  protected $router;

  /**
   * The dynamic router service.
   *
   * @var \Drupal\Core\PathProcessor\InboundPathProcessorInterface
   */
  protected $pathProcessor;

  /**
   * Site config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $siteConfig;

  /**
   * Breadcrumb config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The title resolver.
   *
   * @var \Drupal\Core\Controller\TitleResolverInterface
   */
  protected $titleResolver;

  /**
   * The current user object.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The menu link manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManager
   */
  protected $menuLinkManager;

  /**
   * Constructs the PathBasedBreadcrumbBuilder.
   *
   * @param \Drupal\Core\Routing\RequestContext $context
   *   The router request context.
   * @param \Drupal\Core\Access\AccessManagerInterface $access_manager
   *   The menu link access service.
   * @param \Symfony\Component\Routing\Matcher\RequestMatcherInterface $router
   *   The dynamic router service.
   * @param \Drupal\Core\PathProcessor\InboundPathProcessorInterface $path_processor
   *   The inbound path processor.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Controller\TitleResolverInterface $title_resolver
   *   The title resolver service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user object.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path.
   * @param \Drupal\Core\Menu\MenuLinkManager $menu_link_manager
   *   The menu link manager.
   */
  public function __construct(RequestContext $context, AccessManagerInterface $access_manager, RequestMatcherInterface $router, InboundPathProcessorInterface $path_processor, ConfigFactoryInterface $config_factory, TitleResolverInterface $title_resolver, AccountInterface $current_user, CurrentPathStack $current_path, MenuLinkManager $menu_link_manager) {
    $this->context = $context;
    $this->accessManager = $access_manager;
    $this->router = $router;
    $this->pathProcessor = $path_processor;
    $this->siteConfig = $config_factory->get('system.site');
    $this->config = $config_factory->get('mailjet.settings');
    $this->titleResolver = $title_resolver;
    $this->currentUser = $current_user;
    $this->currentPath = $current_path;
    $this->menuLinkManager = $menu_link_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
      return strpos($route_match->getRouteObject()
              ->getPath(), 'mailjet') !== FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {

    $breadcrumb = new Breadcrumb();
    $links = [];
    $exclude = [];
    $curr_lang = \Drupal::languageManager()->getCurrentLanguage()->getId();

    // General path-based breadcrumbs. Use the actual request path, prior to
    // resolving path aliases, so the breadcrumb can be defined by simply
    // creating a hierarchy of path aliases.
    $path = trim($this->context->getPathInfo(), '/');
    $path = urldecode($path);
    $path_elements = explode('/', $path);
    $front = $this->siteConfig->get('page.front');
    $exclude[$front] = TRUE;
    $exclude['/user'] = TRUE;

    // Because this breadcrumb builder is path and config based, vary cache
    // by the 'url.path' cache context and config changes.
    $breadcrumb->addCacheContexts(['url.path']);
    $breadcrumb->addCacheableDependency($this->config);
    $i = 0;


    while (count($path_elements) > 0) {

      // Copy the path elements for up-casting.
      $route_request = $this->getRequestForPath('/' . implode('/', $path_elements), $exclude);

      if ($route_request) {
        $route_match = RouteMatch::createFromRequest($route_request);
        $access = $this->accessManager->check($route_match, $this->currentUser, NULL, TRUE);
        // The set of breadcrumb links depends on the access result, so merge
        // the access result's cacheability metadata.
        if ($access->isAllowed()) {

          $title = $this->titleResolver->getTitle($route_request, $route_match->getRouteObject());

          if (!isset($title)) {


            // Fallback to using the raw path component as the title if the
            // route is missing a _title or _title_callback attribute.
            if (!isset($title)) {
              $title = str_replace([
                '-',
                '_',
              ], ' ', Unicode::ucfirst(end($path_elements)));
            }
          }


          $url = Url::fromRouteMatch($route_match);
          $links[] = new Link($title, $url);

          unset($title);
          $i++;
        }
      }

      array_pop($path_elements);
    }

    // Add the home link, if desired.
    if ($path && '/' . $path != $front && $path != $curr_lang) {
      $links[] = Link::createFromRoute('Home', '<front>');
    }

    $links = array_reverse($links);

    if (TRUE) {
      $links = $this->removeRepeatedSegments($links);
    }

    return $breadcrumb->setLinks($links);
  }

  /**
   * Remove duplicate repeated segments.
   *
   * @param Link[] $links
   *   The links.
   *
   * @return Link[]
   *   The new links.
   */
  protected function removeRepeatedSegments(array $links) {
    $newLinks = [];

    /** @var Link $last */
    $last = NULL;

    foreach ($links as $link) {
      if (empty($last) || (!$this->linksAreEqual($last, $link))) {
        $newLinks[] = $link;
      }

      $last = $link;
    }

    return $newLinks;
  }

  /**
   * Compares two breadcrumb links for equality.
   *
   * @param \Drupal\Core\Link $link1
   *   The first link.
   * @param \Drupal\Core\Link $link2
   *   The second link.
   *
   * @return bool
   *   TRUE if equal, FALSE otherwise.
   */
  protected function linksAreEqual(Link $link1, Link $link2) {
    $links_equal = TRUE;

    if ($link1->getText() != $link2->getText()) {
      $links_equal = FALSE;
    }

    if ($link1->getUrl()->getInternalPath() != $link2->getUrl()
        ->getInternalPath()) {
      $links_equal = FALSE;
    }

    return $links_equal;
  }

  /**
   * Matches a path in the router.
   *
   * @param string $path
   *   The request path with a leading slash.
   * @param array $exclude
   *   An array of paths or system paths to skip.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   A populated request object or NULL if the path couldn't be matched.
   */
  protected function getRequestForPath($path, array $exclude) {
    if (!empty($exclude[$path])) {
      return NULL;
    }
    // @todo Use the RequestHelper once https://www.drupal.org/node/2090293 is
    //   fixed.
    $request = Request::create($path);
    // Performance optimization: set a short accept header to reduce overhead in
    // AcceptHeaderMatcher when matching the request.
    $request->headers->set('Accept', 'text/html');
    // Find the system path by resolving aliases, language prefix, etc.
    $processed = $this->pathProcessor->processInbound($path, $request);
    if (empty($processed) || !empty($exclude[$processed])) {
      // This resolves to the front page, which we already add.
      return NULL;
    }
    $this->currentPath->setPath($processed, $request);
    // Attempt to match this path to provide a fully built request.
    try {
      $request->attributes->add($this->router->matchRequest($request));
      return $request;
    } catch (ParamNotConvertedException $e) {
      return NULL;
    } catch (ResourceNotFoundException $e) {
      return NULL;
    } catch (MethodNotAllowedException $e) {
      return NULL;
    } catch (AccessDeniedHttpException $e) {
      return NULL;
    }
  }

}
