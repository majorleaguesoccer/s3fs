<?php

namespace Drupal\s3fs\StreamWrapper;

use Aws\CacheInterface;
use Aws\S3\S3Client;
use Aws\S3\StreamWrapper;
use Aws\S3\S3ClientInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Link;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Drupal\s3fs\S3fsException;

/**
 * Defines a Drupal s3 (s3://) stream wrapper class.
 *
 * Provides support for storing files on the amazon s3 file system with the
 * Drupal file interface.
 */
class S3fsStream extends StreamWrapper implements StreamWrapperInterface {

  use StringTranslationTrait;

  const API_VERSION = '2006-03-01';

  /** @var resource|null Stream context (this is set by PHP) */
  public $context;

  /** @var array Hash of opened stream parameters */
  private $params = [];

  /** @var array Module configuration for stream */
  private $config = [];

  /** @var string Mode in which the stream was opened */
  private $mode;

  /** @var string Instance uri referenced as "<scheme>://key" */
  protected $uri = NULL;

  /** @var \Aws\S3\S3Client The AWS SDK for PHP S3Client object */
  protected $s3 = NULL;

  /** @var string The opened protocol (e.g., "s3") */
  private $protocol = 's3';

  /** @var string Domain we use to access files over http */
  protected $domain = NULL;

  /** @var array Directory listing used by the dir_* methods */
  protected $dir = NULL;

  /** @var array Map for files that should be delivered with a torrent URL */
  protected $torrents = array();

  /** @var array Files that the user has said must be downloaded, rather than viewed */
  protected $saveas = array();

  /** @var array Files which should be created with URLs that eventually time out */
  protected $presignedURLs = array();

  /** @var array Default map for determining file mime types */
  protected static $mimeTypeMapping = NULL;

  /** @var bool Indicates the current error state in the wrapper */
  protected $_error_state = FALSE;

  /**
   * S3fsStream constructor.
   *
   * Creates the \Aws\S3\S3Client client object and activates the options
   * specified on the S3 File System Settings page.
   */
  public function __construct() {
    // Since S3fsStreamWrapper is always constructed with the same inputs (the
    // file URI is not part of construction), we store the constructed settings
    // statically. This is important for performance because the way Drupal's
    // APIs are used causes stream wrappers to be frequently re-constructed.
    // Get the S3 Client object and register the stream wrapper again so it is
    // configured as needed.
    $settings = &drupal_static('S3fsStream_constructed_settings');

    if ($settings !== NULL) {
      $this->config = $settings['config'];
      $this->domain = $settings['domain'];
      $this->torrents = $settings['torrents'];
      $this->presignedURLs = $settings['presignedURLs'];
      $this->saveas = $settings['saveas'];
      $this->s3 = $this->getClient();
      $this->register($this->s3);
      return;
    }

    $config = \Drupal::config('s3fs.settings');
    foreach ($config->get() as $prop => $value) {
      $this->config[$prop] = $value;
    }

    $this->s3 = $this->getClient();

    $this->register($this->s3);
    $this->context = stream_context_get_default();
    stream_context_set_option($this->context, 's3', 'seekable', TRUE);

    if (empty($this->config['bucket'])) {
      $link = Link::fromTextAndUrl($this->t('configuration page'), Url::fromRoute('s3fs.admin_settings'));
      \Drupal::logger('S3 File System')
        ->error('Your AmazonS3 bucket name is not configured. Please visit the @config_page.', [
          '@config_page' => $link->toString(),
        ]);
      throw new S3fsException('Your AmazonS3 bucket name is not configured. Please visit the configuration page.');
    }

    // Always use HTTPS when the page is being served via HTTPS, to avoid
    // complaints from the browser about insecure content.
    global $is_https;
    if ($is_https) {
      // We change the config itself, rather than simply using $is_https in
      // the following if condition, because $this->config['use_https'] gets
      // used again later.
      $this->config['use_https'] = TRUE;
    }

    if (!empty($this->config['use_https'])) {
      $scheme = 'https';
    }
    else {
      $scheme = 'http';
    }

    // CNAME support for customizing S3 URLs.
    // If use_cname is not enabled, file URLs do not use $this->domain.
    if (!empty($this->config['use_cname']) && !empty($this->config['domain'])) {
      $domain = UrlHelper::filterBadProtocol($this->config['domain']);
      if ($domain) {
        // If domain is set to a root-relative path, add the hostname back in.
        if (strpos($domain, '/') === 0) {
          $domain = $_SERVER['HTTP_HOST'] . $domain;
        }
        $this->domain = "$scheme://$domain";
      }
      else {
        // Due to the config form's validation, this shouldn't ever happen.
        throw new S3fsException($this->t('The "Use CNAME" option is enabled, but no Domain Name has been set.'));
      }
    }

    // Convert the torrents string to an array.
    if (!empty($this->config['torrents'])) {
      foreach (explode("\n", $this->config['torrents']) as $line) {
        $blob = trim($line);
        if ($blob) {
          $this->torrents[] = $blob;
        }
      }
    }

    // Convert the presigned URLs string to an associative array like
    // array(blob => timeout).
    if (!empty($this->config['presigned_urls'])) {
      foreach (explode(PHP_EOL, $this->config['presigned_urls']) as $line) {
        $blob = trim($line);
        if ($blob) {
          if (preg_match('/(.*)\|(.*)/', $blob, $matches)) {
            $blob = $matches[2];
            $timeout = $matches[1];
            $this->presignedURLs[$blob] = $timeout;
          }
          else {
            $this->presignedURLs[$blob] = 60;
          }
        }
      }
    }

    // Convert the forced save-as string to an array.
    if (!empty($this->config['saveas'])) {
      foreach (explode(PHP_EOL, $this->config['saveas']) as $line) {
        $blob = trim($line);
        if ($blob) {
          $this->saveas[] = $blob;
        }
      }
    }

    // Save all the work we just did, so that subsequent S3fsStreamWrapper
    // constructions don't have to repeat it.
    $settings['config'] = $this->config;
    $settings['domain'] = $this->domain;
    $settings['torrents'] = $this->torrents;
    $settings['presignedURLs'] = $this->presignedURLs;
    $settings['saveas'] = $this->saveas;
  }

  private function getClient() {
    return S3Client::factory([
      'credentials' => [
        'key' => $this->config['access_key'],
        'secret' => $this->config['secret_key'],
      ],
      'region' => $this->config['region'],
      'version' => static::API_VERSION,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    return StreamWrapperInterface::NORMAL;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->t('S3 File System');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Amazon Simple Storage Service.');
  }

  /**
   * Gets the path that the wrapper is responsible for.
   *
   * This function isn't part of DrupalStreamWrapperInterface, but the rest
   * of Drupal calls it as if it were, so we need to define it.
   *
   * @return string
   *   The empty string. Since this is a remote stream wrapper,
   *   it has no directory path.
   *
   * @see LocalStream::getDirectoryPath()
   */
  public function getDirectoryPath() {
    return '';
  }

  /**
   * {@inheritdoc}
   *
   * Sets the stream resource URI. URIs are formatted as "<scheme>://filepath".
   *
   * @param string $uri
   *   The URI that should be used for this instance.
   */
  public function setUri($uri) {
    $this->uri = $uri;
  }

  /**
   * {@inheritdoc}
   *
   * Returns the stream resource URI, which looks like "<scheme>://filepath".
   *
   * @return string
   *   The current URI of the instance.
   */
  public function getUri() {
    return $this->uri;
  }

  /**
   * {@inheritdoc}
   *
   * This wrapper does not support realpath().
   *
   * @return bool
   *   Always returns FALSE.
   */
  public function realpath() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Returns a web accessible URL for the resource.
   *
   * The format of the returned URL will be different depending on how the S3
   * integration has been configured on the S3 File System admin page.
   *
   * @return string
   *   A web accessible URL for the resource.
   */
  public function getExternalUrl() {
    // In case we're on Windows, replace backslashes with forward-slashes.
    // Note that $uri is the unaltered value of the File's URI, while
    // $s3_key may be changed at various points to account for implementation
    // details on the S3 side (e.g. root_folder, s3fs-public).
    // @todo review s3_key and uri to unify
    $s3_key = $uri = str_replace('\\', '/', file_uri_target($this->uri));

    // If this is a private:// file, it must be served through the
    // system/files/$path URL, which allows Drupal to restrict access
    // based on who's logged in.
    if (\Drupal::service('file_system')->uriScheme($this->uri) == 'private') {
      // @todo review patch
      // Convert backslashes from windows filenames to forward slashes.
      $path = str_replace('\\', '/', $uri);
      $relative_url = Url::fromUserInput("/system/files/$path");
      return Link::fromTextAndUrl($relative_url, $relative_url);
    }

    // When generating an image derivative URL, e.g. styles/thumbnail/blah.jpg,
    // if the file doesn't exist, provide a URL to s3fs's special version of
    // image_style_deliver(), which will create the derivative when that URL
    // gets requested.
    $path_parts = explode('/', $uri);
    if ($path_parts[0] == 'styles' && substr($uri, -4) != '.css') {
      if (!$this->_s3fs_get_object($this->uri)) {

        $args = $path_parts;
        array_shift($args);
        $style = array_shift($args);
        $scheme = array_shift($args);
        $filename = implode('/', $args);
        $original_image = "$scheme://$filename";
        // Load the image style configuration entity.
        $style = ImageStyle::load($style);
        $destination = $style->buildUri($original_image);
        $style->createDerivative($original_image, $destination);
      }
    }

    // Deal with public:// files.
    if (\Drupal::service('file_system')->uriScheme($this->uri) == 'public') {
      // Rewrite all css/js file paths unless the user has told us not to.
      if (!$this->config['no_rewrite_cssjs']) {
        if (substr($uri, -4) == '.css') {
          // Send requests for public CSS files to /s3fs-css/path/to/file.css.
          // Users must set that path up in the webserver config as a proxy into
          // their S3 bucket's s3fs-public/ folder.
          return "{$GLOBALS['base_url']}/s3fs-css/" . UrlHelper::encodePath($uri);
        }
        else {
          if (substr($uri, -3) == '.js') {
            // Send requests for public JS files to /s3fs-js/path/to/file.js.
            // Like with CSS, the user must set up that path as a proxy.
            return "{$GLOBALS['base_url']}/s3fs-js/" . UrlHelper::encodePath($uri);
          }
        }
      }

      // public:// files are stored in S3 inside the s3fs_public_folder.
      $public_folder = !empty($this->config['public_folder']) ? $this->config['public_folder'] : 's3fs-public';
      $s3_key = "{$public_folder}/$s3_key";
    }

    // Set up the URL settings as speciied in our settings page.
    $url_settings = [
      'torrent' => FALSE,
      'presigned_url' => FALSE,
      'timeout' => 60,
      'forced_saveas' => FALSE,
      'api_args' => ['Scheme' => !empty($this->config['use_https']) ? 'https' : 'http'],
      'custom_GET_args' => [],
    ];

    // Presigned URLs.
    foreach ($this->presignedURLs as $blob => $timeout) {
      // ^ is used as the delimeter because it's an illegal character in URLs.
      if (preg_match("^$blob^", $uri)) {
        $url_settings['presigned_url'] = TRUE;
        $url_settings['timeout'] = $timeout;
        break;
      }
    }
    // Forced Save As.
    foreach ($this->saveas as $blob) {
      if (preg_match("^$blob^", $uri)) {
        $filename = basename($uri);
        $url_settings['api_args']['ResponseContentDisposition'] = "attachment; filename=\"$filename\"";
        $url_settings['forced_saveas'] = TRUE;
        break;
      }
    }

    // Allow other modules to change the URL settings.
    \Drupal::moduleHandler()->alter('s3fs_url_settings', $url_settings, $s3_key);

    // If a root folder has been set, prepend it to the $s3_key at this time.
    if (!empty($this->config['root_folder'])) {
      $s3_key = "{$this->config['root_folder']}/$s3_key";
    }

    if (empty($this->config['use_cname'])) {
      // We're not using a CNAME, so we ask S3 for the URL.
      $expires = NULL;
      if ($url_settings['presigned_url']) {
        $expires = "+{$url_settings['timeout']} seconds";
      }
      else {
        // Due to Amazon's security policies (see Request client eters section @
        // http://docs.aws.amazon.com/AmazonS3/latest/API/RESTObjectGET.html),
        // only signed requests can use request parameters.
        // Thus, we must provide an expiry time for any URLs which specify
        // Response* API args. Currently, this only includes "Forced Save As".
        foreach ($url_settings['api_args'] as $key => $arg) {
          if (strpos($key, 'Response') === 0) {
            $expires = "+10 years";
            break;
          }
        }
      }

      if ($url_settings['presigned_url']) {
        $cmd = $this->s3->getCommand('GetObject', array(
          'Bucket' => $this->config['bucket'],
          'Key' => $s3_key,
        ));
        $external_url = (string) $this->s3->createPresignedRequest($cmd, $expires)->getUri();
      }
      else {
        $external_url = $this->s3->getObjectUrl($this->config['bucket'], $s3_key);
      }
    }
    else {
      // We are using a CNAME, so we need to manually construct the URL.
      $external_url = rtrim($this->domain, '/') . '/' . UrlHelper::encodePath($s3_key);
    }

    // If this file is versioned, append the version number as a GET arg to
    // ensure that browser caches will be bypassed upon version changes.
    $meta = $this->_read_cache($this->uri);
    if (!empty($meta['version'])) {
      $external_url = $this->_append_get_arg($external_url, $meta['version']);
    }

    // Torrents can only be created for publicly-accessible files:
    // https://forums.aws.amazon.com/thread.jspa?threadID=140949
    // So Forced SaveAs and Presigned URLs cannot be served as torrents.
    if (!$url_settings['forced_saveas'] && !$url_settings['presigned_url']) {
      foreach ($this->torrents as $blob) {
        if (preg_match("^$blob^", $uri)) {
          // You get a torrent URL by adding a "torrent" GET arg.
          $external_url = $this->_append_get_arg($external_url, 'torrent');
          break;
        }
      }
    }

    // If another module added a 'custom_GET_args' array to the url settings, process it here.
    if (!empty($url_settings['custom_GET_args'])) {
      foreach ($url_settings['custom_GET_args'] as $name => $value) {
        $external_url = $this->_append_get_arg($external_url, $name, $value);
      }
    }
    return $external_url;
  }

  /**
   * {@inheritdoc}
   *
   * Support for fopen(), file_get_contents(), file_put_contents() etc.
   *
   * @param string $uri
   *   The URI of the file to open.
   * @param string $mode
   *   The file mode. Only 'r', 'w', 'a', and 'x' are supported.
   * @param int $options
   *   A bit mask of STREAM_USE_PATH and STREAM_REPORT_ERRORS.
   * @param string $opened_path
   *   An OUT parameter populated with the path which was opened.
   *   This wrapper does not support this parameter.
   *
   * @return bool
   *   TRUE if file was opened successfully. Otherwise, FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-open.php
   */
  public function stream_open($uri, $mode, $options, &$opened_path) {
    $this->setUri($uri);
    $converted = $this->convertUriToKeyedPath($uri);
    return parent::stream_open($converted, $mode, $options, $opened_path);
  }

  /**
   * {@inheritdoc}
   *
   * This wrapper does not support flock().
   *
   * @return bool
   *   Always Returns FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-lock.php
   */
  public function stream_lock($operation) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for fflush(). Flush current cached stream data to a file in S3.
   *
   * @return bool
   *   TRUE if data was successfully stored in S3.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-flush.php
   */
  public function stream_flush() {
    // Prepare upload parameters.
    $options = $this->getOptions();
    $params = $this->getCommandParams($this->getUri());
    $options[$this->protocol]['ContentType'] = \Drupal::service('file.mime_type.guesser')
      ->guess($params['Key']);

    if (\Drupal::service('file_system')->uriScheme($this->uri) != 'private') {
      // All non-private files uploaded to S3 must be set to public-read, or users' browsers
      // will get PermissionDenied errors, and torrent URLs won't work.
      $options[$this->protocol]['ACL'] = 'public-read';
    }
    // Set the Cache-Control header, if the user specified one.
    if (!empty($this->config['cache_control_header'])) {
      $options[$this->protocol]['CacheControl'] = $this->config['cache_control_header'];
    }

    if (!empty($this->config['encryption'])) {
      $options[$this->protocol]['ServerSideEncryption'] = $this->config['encryption'];
    }

    // Allow other modules to alter the upload params.
    \Drupal::moduleHandler()->alter('s3fs_upload_params', $options[$this->protocol]);

    stream_context_set_option($this->context, $options);

    if (parent::stream_flush()) {
      $this->writeUriToCache($this->uri);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * @see http://php.net/manual/en/streamwrapper.stream-metadata.php
   */
  public function stream_metadata($uri, $option, $value) {
    return FALSE;
  }


  /**
   * {@inheritdoc}
   *
   * Since Windows systems do not allow it and it is not needed for most use
   * cases anyway, this method is not supported on local files and will trigger
   * an error and return false. If needed, custom subclasses can provide
   * OS-specific implementations for advanced use cases.
   */
  public function stream_set_option($option, $arg1, $arg2) {
    trigger_error('stream_set_option() not supported for local file based stream wrappers', E_USER_WARNING);
    return FALSE;
  }

  //@todo: Needs Work
  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size) {
    return ftruncate($this->handle, $new_size);
  }

  /**
   * {@inheritdoc}
   *
   * Support for unlink().
   *
   * @param string $uri
   *   The uri of the resource to delete.
   *
   * @return bool
   *   TRUE if resource was successfully deleted, regardless of whether or not
   *   the file actually existed.
   *   FALSE if the call to S3 failed, in which case the file will not be
   *   removed from the cache.
   *
   * @see http://php.net/manual/en/streamwrapper.unlink.php
   */
  public function unlink($uri) {
    $this->setUri($uri);
    $converted = $this->convertUriToKeyedPath($uri);
    if (parent::unlink($converted)) {
      $this->_delete_cache($uri);
      clearstatcache(TRUE, $uri);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for rename().
   *
   * If $to_uri exists, this file will be overwritten. This behavior is
   * identical to the PHP rename() function.
   *
   * @param string $path_from
   *   The uri of the file to be renamed.
   * @param string $path_to
   *   The new uri for the file.
   *
   * @return bool
   *   TRUE if file was successfully renamed. Otherwise, FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.rename.php
   */
  public function rename($path_from, $path_to) {
    // Set access for new item in stream context.
    if (\Drupal::service('file_system')->uriScheme($path_from) != 'private') {
      stream_context_set_option($this->context, 's3', 'ACL', 'public-read');
    }

    // If parent succeeds in renaming, updated local metadata and cache.
    if (parent::rename($this->convertUriToKeyedPath($path_from), $this->convertUriToKeyedPath($path_to))) {
      $metadata = $this->_read_cache($path_from);
      $metadata['uri'] = $path_to;
      $this->_write_cache($metadata);
      $this->_delete_cache($path_from);
      clearstatcache(TRUE, $path_from);
      clearstatcache(TRUE, $path_to);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Gets the name of the parent directory of a given path.
   *
   * This method is usually accessed through \Drupal::service('file_system')->dirname(),
   * which wraps around the normal PHP dirname() function, since it doesn't
   * support stream wrappers.
   *
   * @param string $uri
   *   An optional URI.
   *
   * @return string
   *   The directory name, or FALSE if not applicable.
   *
   * @see \Drupal::service('file_system')->dirname()
   */
  public function dirname($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }
    $scheme = \Drupal::service('file_system')->uriScheme($uri);
    $dirname = dirname(file_uri_target($uri));

    // When the dirname() call above is given '$scheme://', it returns '.'.
    // But '$scheme://.' is an invalid uri, so we return "$scheme://" instead.
    if ($dirname == '.') {
      $dirname = '';
    }

    return "$scheme://$dirname";
  }

  /**
   * {@inheritdoc}
   *
   * Support for mkdir().
   *
   * @param string $uri
   *   The URI to the directory to create.
   * @param int $mode
   *   Permission flags - see mkdir().
   * @param int $options
   *   A bit mask of STREAM_REPORT_ERRORS and STREAM_MKDIR_RECURSIVE.
   *
   * @return bool
   *   TRUE if the directory was successfully created. Otherwise, FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.mkdir.php
   */
  public function mkdir($uri, $mode, $options) {
    // Some Drupal plugins call mkdir with a trailing slash. We mustn't store
    // that slash in the cache.
    $uri = rtrim($uri, '/');

    clearstatcache(TRUE, $uri);
    // If this URI already exists in the cache, return TRUE if it's a folder
    // (so that recursive calls won't improperly report failure when they
    // reach an existing ancestor), or FALSE if it's a file (failure).
    $test_metadata = $this->_read_cache($uri);
    if ($test_metadata) {
      return (bool) $test_metadata['dir'];
    }

    $metadata = $this->convertMetadata($uri, []);
    $this->_write_cache($metadata);

    // If the STREAM_MKDIR_RECURSIVE option was specified, also create all the
    // ancestor folders of this uri, except for the root directory.
    $parent_dir = \Drupal::service('file_system')->dirname($uri);
    if (($options & STREAM_MKDIR_RECURSIVE) && file_uri_target($parent_dir) != '') {
      return $this->mkdir($parent_dir, $mode, $options);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for rmdir().
   *
   * @param string $uri
   *   The URI to the folder to delete.
   * @param int $options
   *   A bit mask of STREAM_REPORT_ERRORS.
   *
   * @return bool
   *   TRUE if folder is successfully removed.
   *   FALSE if $uri isn't a folder, or the folder is not empty.
   *
   * @see http://php.net/manual/en/streamwrapper.rmdir.php
   */
  public function rmdir($uri, $options) {
    if (!$this->_path_is_dir($uri)) {
      return FALSE;
    }

    // We need a version of $uri with no / because folders are cached with no /.
    // We also need one with the /, because it might be a file in S3 that
    // ends with /. In addition, we must differentiate against files with this
    // folder's name as a substring.
    // e.g. rmdir('s3://foo/bar') should ignore s3://foo/barbell.jpg.
    $bare_path = rtrim($uri, '/');
    $slash_path = $bare_path . '/';

    // Check if the folder is empty.
    $query = \Drupal::database()->select('s3fs_file', 's');
    $query->fields('s')
      ->condition('uri', $query->escapeLike($slash_path) . '%', 'LIKE');

    // @todo review if it's possible replace by fetchAssoc
    $files = $query->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    // If the folder is empty, it's eligible for deletion.
    if (empty($files)) {
      if (parent::rmdir($this->convertUriToKeyedPath($uri), $options)) {
        $this->_delete_cache($uri);
        clearstatcache(TRUE, $uri);
        return TRUE;
      }
    }

    // The folder is non-empty.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for stat().
   *
   * @param string $uri
   *   The URI to get information about.
   * @param int $flags
   *   A bit mask of STREAM_URL_STAT_LINK and STREAM_URL_STAT_QUIET.
   *   S3fsStreamWrapper ignores this value.
   *
   * @return array
   *   An array with file status, or FALSE in case of an error.
   *
   * @see http://php.net/manual/en/streamwrapper.url-stat.php
   */
  public function url_stat($uri, $flags) {
    $this->setUri($uri);
    return $this->_stat($uri);
  }

  /**
   * {@inheritdoc}
   *
   * Support for opendir().
   *
   * @param string $uri
   *   The URI to the directory to open.
   * @param int $options
   *   A flag used to enable safe_mode.
   *   This wrapper doesn't support safe_mode, so this parameter is ignored.
   *
   * @return bool
   *   TRUE on success. Otherwise, FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-opendir.php
   */
  public function dir_opendir($uri, $options = NULL) {
    if (!$this->_path_is_dir($uri)) {
      return FALSE;
    }

    $scheme = \Drupal::service('file_system')->uriScheme($uri);
    $base_path = rtrim($uri, '/');
    $slash_path = $base_path . '/';

    // If this path was originally a root folder (e.g. s3://), the above code
    // removed *both* slashes but only added one back. So we need to add
    // back the second slash.
    if ($slash_path == "$scheme:/") {
      $slash_path = "$scheme://";
    }

    // Get the list of paths for files and folders which are children of the
    // specified folder, but not grandchildren.
    $query = \Drupal::database()->select('s3fs_file', 's');
    $query->fields('s', ['uri']);
    $query->condition('uri', $query->escapeLike($slash_path) . '%', 'LIKE');
    $query->condition('uri', $query->escapeLike($slash_path) . '%/%', 'NOT LIKE');
    $child_paths = $query->execute()->fetchCol(0);

    $this->dir = [];
    foreach ($child_paths as $child_path) {
      $this->dir[] = basename($child_path);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Support for readdir().
   *
   * @return string
   *   The next filename, or FALSE if there are no more files in the directory.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-readdir.php
   */
  public function dir_readdir() {
    $entry = each($this->dir);
    return $entry ? $entry['value'] : FALSE;
  }

  /***************************************************************************
   * Public Functions for External Use of the Wrapper
   ***************************************************************************/

  /**
   * Wait for the specified file to exist in the bucket.
   *
   * @param string $uri
   *   The URI of the file.
   *
   * @return bool
   *   Returns TRUE once the waiting finishes, or FALSE if the file does not
   *   begin to exist within 10 seconds.
   */
  public function waitUntilFileExists($uri) {
    // Retry ten times, once every second.
    $params = $this->getCommandParams($uri, FALSE);
    $params['@waiter'] = array(
      'delay' => 1,
      'maxAttempts' => 10,
    );
    try {
      $this->s3->waitUntil('ObjectExists', $params);
      return TRUE;
    }
    catch (S3fsException $e) {
      watchdog_exception('S3FS', $e);
      return FALSE;
    }
  }

  /**
   * Write the file at the given URI into the metadata cache.
   *
   * This function is public so that other code can upload files to S3 and
   * then have us write the correct metadata into our cache.
   */
  public function writeUriToCache($uri) {
    if ($this->waitUntilFileExists($uri)) {
      $metadata = $this->_get_metadata_from_s3($uri);
      $this->_write_cache($metadata);
      clearstatcache(TRUE, $uri);
    }
  }

  /***************************************************************************
   * Internal Functions
   ***************************************************************************/

  /**
   * Get the status of the file with the specified URI.
   *
   * Implementation of a stat method to ensure that remote files don't fail
   * checks when they should pass.
   *
   * @param $uri
   *
   * @return array|bool
   *   An array with file status, or FALSE if the file doesn't exist.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-stat.php
   */
  protected function _stat($uri) {
    $metadata = $this->_s3fs_get_object($uri);
    if ($metadata) {
      $stat = [];
      $stat[0] = $stat['dev'] = 0;
      $stat[1] = $stat['ino'] = 0;
      // Use the S_IFDIR posix flag for directories, S_IFREG for files.
      // All files are considered writable, so OR in 0777.
      $stat[2] = $stat['mode'] = ($metadata['dir'] ? 0040000 : 0100000) | 0777;
      $stat[3] = $stat['nlink'] = 0;
      $stat[4] = $stat['uid'] = 0;
      $stat[5] = $stat['gid'] = 0;
      $stat[6] = $stat['rdev'] = 0;
      $stat[7] = $stat['size'] = 0;
      $stat[8] = $stat['atime'] = 0;
      $stat[9] = $stat['mtime'] = 0;
      $stat[10] = $stat['ctime'] = 0;
      $stat[11] = $stat['blksize'] = 0;
      $stat[12] = $stat['blocks'] = 0;

      if (!$metadata['dir']) {
        $stat[4] = $stat['uid'] = 's3fs';
        $stat[7] = $stat['size'] = $metadata['filesize'];
        $stat[8] = $stat['atime'] = $metadata['timestamp'];
        $stat[9] = $stat['mtime'] = $metadata['timestamp'];
        $stat[10] = $stat['ctime'] = $metadata['timestamp'];
      }
      return $stat;
    }
    return FALSE;
  }

  /**
   * Determine whether the $uri is a directory.
   *
   * @param string $uri
   *   The path of the resource to check.
   *
   * @return bool
   *   TRUE if the resource is a directory.
   */
  protected function _path_is_dir($uri) {
    $metadata = $this->_s3fs_get_object($uri);
    return $metadata ? $metadata['dir'] : FALSE;
  }

  /**
   * Try to fetch an object from the metadata cache.
   *
   * If that file isn't in the cache, we assume it doesn't exist.
   *
   * @param string $uri
   *   The uri of the resource to check.
   *
   * @return array|bool
   *   An array if the $uri exists, otherwise FALSE.
   */
  protected function _s3fs_get_object($uri) {
    // For the root directory, return metadata for a generic folder.
    if (file_uri_target($uri) == '') {
      return $this->convertMetadata('/', []);
    }

    // Trim any trailing '/', in case this is a folder request.
    $uri = rtrim($uri, '/');

    // Check if this URI is in the cache.
    $metadata = $this->_read_cache($uri);

    // If cache ignore is enabled, query S3 for all URIs which aren't in the
    // cache, and non-folder URIs which are.
    if (!empty($this->config['ignore_cache']) && !$metadata['dir']) {
      try {
        // If _get_metadata_from_s3() returns FALSE, the file doesn't exist.
        $metadata = $this->_get_metadata_from_s3($uri);
      }
      catch (\Exception $e) {
        return $this->_trigger_error($e->getMessage());
      }
    }
    return $metadata;
  }

  /**
   * Fetch an object from the file metadata cache table.
   *
   * @param string $uri
   *   The uri of the resource to check.
   *
   * @return array
   *   An array of metadata if the $uri is in the cache. Otherwise, FALSE.
   */
  protected function _read_cache($uri) {
    // Since public:///blah.jpg and public://blah.jpg refer to the same file
    // (a file named blah.jpg at the root of the file system), we'll sometimes
    // receive files with a /// in their URI. This messes with our caching
    // scheme, though, so we need to remove the extra /.
    if (strpos($uri, 'public:///') === 0) {
      $uri = preg_replace('^public://[/]+^', 'public://', $uri);
    }
    else {
      if (strpos($uri, 'private:///') === 0) {
        $uri = preg_replace('^private://[/]+^', 'private://', $uri);
      }
    }
    //@todo: Cache Implementation

    $record = \Drupal::database()->select('s3fs_file', 's')
      ->fields('s')
      ->condition('uri', $uri, '=')
      ->execute()
      ->fetchAssoc();
    return $record ? $record : FALSE;
  }

  /**
   * Write an object's (and its ancestor folders') metadata to the cache.
   *
   * @param array $metadata
   *   An associative array of file metadata in this format:
   *     'uri' => The full URI of the file, including the scheme.
   *     'filesize' => The size of the file, in bytes.
   *     'timestamp' => The file's create/update timestamp.
   *     'dir' => A boolean indicating whether the object is a directory.
   *
   * @throws
   *   Exceptions which occur in the database call will percolate.
   */
  protected function _write_cache($metadata) {
    // Since public:///blah.jpg and public://blah.jpg refer to the same file
    // (a file named blah.jpg at the root of the file system), we'll sometimes
    // receive files with a /// in their URI. This messes with our caching
    // scheme, though, so we need to remove the extra /.
    //@todo: Work this out if needed
    /*if (strpos($metadata['uri'], 'public:///') === 0) {
      $metadata['uri'] = preg_replace('^public://[/]+^', 'public://', $metadata['uri']);
    }
    else if (strpos($metadata['uri'], 'private:///') === 0) {
      $metadata['uri'] = preg_replace('^private://[/]+^', 'private://', $metadata['uri']);
    }*/

    \Drupal::database()->merge('s3fs_file')
      ->key(['uri' => $metadata['uri']])
      ->fields($metadata)
      ->execute();

    // Clear this URI from the Drupal cache, to ensure the next read isn't
    // from a stale cache entry.
//    $cid = S3FS_CACHE_PREFIX . $metadata['uri'];
//    $cache = \Drupal::cache('S3FS_CACHE_BIN');
//    $cache->delete($cid);

    $dirname = \Drupal::service('file_system')->dirname($metadata['uri']);
    // If this file isn't in the root directory, also write this file's
    // ancestor folders to the cache.
    if (file_uri_target($dirname) != '') {
      $this->mkdir($dirname, NULL, STREAM_MKDIR_RECURSIVE);
    }
  }

  /**
   * Delete an object's metadata from the cache.
   *
   * @param mixed $uri
   *   A string (or array of strings) containing the URI(s) of the object(s)
   *   to be deleted.
   *
   * @throws
   *   Exceptions which occur in the database call will percolate.
   */
  protected function _delete_cache($uri) {
    if (!is_array($uri)) {
      $uri = [$uri];
    }

    // Build an OR query to delete all the URIs at once.
    $delete_query = \Drupal::database()->delete('s3fs_file');
    $or = $delete_query->orConditionGroup();
    foreach ($uri as $u) {
      $or->condition('uri', $u, '=');
      // Clear this URI from the Drupal cache.
      // @todo in cache issue
      // $cid = S3FS_CACHE_PREFIX . $u;
      // $cache = \Drupal::cache('S3FS_CACHE_BIN');
      // $cache->delete($cid);
    }
    $delete_query->condition($or);
    return $delete_query->execute();
  }

  /**
   * Returns the converted metadata for an object in S3.
   *
   * @param string $uri
   *   The URI for the object in S3.
   *
   * @return array
   *   An array of DB-compatible file metadata.
   *
   * @throws S3fsException
   *   Any exception raised by the listObjects() S3 command will percolate
   *   out of this function.
   */
  protected function _get_metadata_from_s3($uri) {
    $params = $this->getCommandParams($uri);
    try {
      $result = $this->s3->headObject($params);
    }
    catch (S3fsException $e) {
      // headObject() throws this exception if the requested key doesn't exist
      // in the bucket.
      return FALSE;
    }

    return $this->convertMetadata($uri, $result);
  }

  /**
   * Triggers one or more errors.
   *
   * @param string|array $errors
   *   Errors to trigger.
   * @param mixed $flags
   *   If set to STREAM_URL_STAT_QUIET, no error or exception is triggered.
   *
   * @return bool
   *   Always returns FALSE.
   */
  protected function _trigger_error($errors, $flags = NULL) {
    if ($flags != STREAM_URL_STAT_QUIET) {
      trigger_error(implode("\n", (array) $errors), E_USER_ERROR);
    }
    $this->_error_state = TRUE;
    return FALSE;
  }

  /**
   * Helper function to safely append a GET argument to a given base URL.
   *
   * @param string $base_url
   *   The URL onto which the GET arg will be appended.
   * @param string $name
   *   The name of the GET argument.
   * @param string $value
   *   The value of the GET argument. Optional.
   *
   * @return string
   *   The converted path GET argument.
   */
  protected static function _append_get_arg($base_url, $name, $value = NULL) {
    $separator = strpos($base_url, '?') === FALSE ? '?' : '&';
    $new_url = "{$base_url}{$separator}{$name}";
    if ($value !== NULL) {
      $new_url .= "=$value";
    }
    return $new_url;
  }

  /**
   * Convert file metadata returned from S3 into a metadata cache array.
   *
   * @param string $uri
   *   The uri of the resource.
   * @param array $s3_metadata
   *   An array containing the collective metadata for the object in S3.
   *   The caller may send an empty array here to indicate that the returned
   *   metadata should represent a directory.
   *
   * @return array
   *   A file metadata cache array.
   */
  protected function convertMetadata($uri, $s3_metadata) {
    // Need to fill in a default value for everything, so that DB calls
    // won't complain about missing fields.
    $metadata = [
      'uri' => $uri,
      'filesize' => 0,
      'timestamp' => REQUEST_TIME,
      'dir' => 0,
      'version' => '',
    ];

    if (empty($s3_metadata)) {
      // The caller wants directory metadata.
      $metadata['dir'] = 1;
    }
    else {
      // The filesize value can come from either the Size or ContentLength
      // attribute, depending on which AWS API call built $s3_metadata.
      if (isset($s3_metadata['ContentLength'])) {
        $metadata['filesize'] = $s3_metadata['ContentLength'];
      }
      else {
        if (isset($s3_metadata['Size'])) {
          $metadata['filesize'] = $s3_metadata['Size'];
        }
      }

      if (isset($s3_metadata['LastModified'])) {
        $metadata['timestamp'] = date('U', strtotime($s3_metadata['LastModified']));
      }

      if (isset($s3_metadata['VersionId'])) {
        $metadata['version'] = $s3_metadata['VersionId'];
      }
    }
    return $metadata;
  }

  /**
   * {@inheritdoc}
   *
   * Get the stream's context options or remove them if wanting default.
   *
   * @param bool $removeContextData
   *   Whether to remove the stream's context information.
   *
   * @return array
   *   An array of options.
   *
   * @todo review access
   */
  public function getOptions($removeContextData = false) {
    // Context is not set when doing things like stat
    if (is_null($this->context)) {
      $this->context = stream_context_get_default();
    }
    $options = stream_context_get_options($this->context);

    if ($removeContextData) {
      unset($options['client'], $options['seekable'], $options['cache']);
    }

    return $options;
  }

  /**
   * Converts a Drupal URI path into what is expected to be stored in S3.
   *
   * @param $uri
   *   An appropriate URI formatted like 'protocol://path'.
   * @param bool $prepend_bucket
   *   Whether to prepend the bucket name. S3's stream wrapper requires this for
   *   some functions.
   *
   * @return string
   *   A converted string ready for S3 to process it.
   */
  protected function convertUriToKeyedPath($uri, $prepend_bucket = TRUE) {
    // Remove the protocol
    $parts = explode('://', $uri);

    if (!empty($parts[1])) {
      // public:// file are all placed in the s3fs_public_folder.
      $public_folder = !empty($this->config['public_folder']) ? $this->config['public_folder'] : 's3fs-public';
      $private_folder = !empty($this->config['private_folder']) ? $this->config['private_folder'] : 's3fs-private';
      if (\Drupal::service('file_system')->uriScheme($uri) == 'public') {
        $parts[1] = "$public_folder/{$parts[1]}";
      }
      // private:// file are all placed in the s3fs_private_folder.
      elseif (\Drupal::service('file_system')->uriScheme($uri) == 'private') {
        $parts[1] = "$private_folder/{$parts[1]}";
      }

      // If it's set, all files are placed in the root folder.
      if (!empty($this->config['root_folder'])) {
        $parts[1] = "{$this->config['root_folder']}/{$parts[1]}";
      }

      // Prepend the uri with a bucket since AWS SDK expects this.
      if ($prepend_bucket) {
        $parts[1] = $this->config['bucket'] . '/' . $parts[1];
      }
    }

    // Set protocol to S3 so AWS stream wrapper works correctly.
    $parts[0] = 's3';
    return implode('://', $parts);
  }

  /**
   * Return bucket and key for a command array.
   *
   * @param string $uri
   *   Uri to the required object.
   *
   * @return array
   *   A modified path to the key in S3.
   */
  protected function getCommandParams($uri) {
    $convertedPath = $this->convertUriToKeyedPath($uri, FALSE);
    $params = $this->getOptions(true);
    $params['Bucket'] = $this->config['bucket'];
    $params['Key'] = file_uri_target($convertedPath);
    return $params;
  }

  /**
   * {@inheritdoc}
   *
   * Ensure the S3 protocol is registered to this class and not parents.
   *
   * @param \Aws\S3\S3ClientInterface $client
   * @param string $protocol
   * @param \Aws\CacheInterface|NULL $cache
   */
  public static function register(S3ClientInterface $client, $protocol = 's3', CacheInterface $cache = null) {
    parent::register($client, $protocol, $cache);
  }

}
