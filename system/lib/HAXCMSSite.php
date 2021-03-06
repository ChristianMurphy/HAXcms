<?php
define('HAXCMS_DEFAULT_THEME', 'simple-blog');
// working with RSS
include_once 'RSS.php';
// a site object
class HAXCMSSite {
  public $name;
  public $manifest;
  public $directory;
  public $basePath = '/';
  public $language = 'en-us';
  /**
   * Load a site based on directory and name
   */
  public function load($directory, $siteBasePath, $name) {
    $this->name = $name;
    $tmpname = urldecode($name);
    $tmpname = $GLOBALS['HAXCMS']->cleanTitle($tmpname, false);
    $this->basePath = $siteBasePath;
    $this->directory = $directory;
    $this->manifest = new JSONOutlineSchema();
    $this->manifest->load($this->directory . '/' . $tmpname . '/site.json');
  }
  /**
   * Initialize a new site with a single page to start the outline
   * @var $directory string file system path
   * @var $siteBasePath string web based url / base_path
   * @var $name string name of the site
   * @var $gitDetails git details
   * @var $domain domain information
   * 
   * @return HAXCMSSite object
   */
  public function newSite($directory, $siteBasePath, $name, $gitDetails, $domain = NULL) {
    // calls must set basePath internally to avoid page association issues
    $this->basePath = $siteBasePath;
    $this->directory = $directory;
    $this->name = $name;
    // clean up name so it can be in a URL / published
    $tmpname = urldecode($name);
    $tmpname = $GLOBALS['HAXCMS']->cleanTitle($tmpname, false);
    $loop = 0;
    $newName = $tmpname;
    while (file_exists($directory . '/' . $newName)) {
      $loop++;
      $newName = $tmpname . '-' . $loop;
    }
    $tmpname = $newName;
    // attempt to shift it on the file system
    $this->recurseCopy(HAXCMS_ROOT . '/system/boilerplate/site', $directory . '/' . $tmpname);
    // create symlink to make it easier to resolve things to single built asset buckets
    @symlink('../../build', $directory . '/' . $tmpname . '/build');
    // symlink to do local development if needed
    @symlink('../../dist', $directory . '/' . $tmpname . '/dist');
    @symlink('../../node_modules', $directory . '/' . $tmpname . '/node_modules');
    // links babel files so that unification is easier
    @symlink('../../../babel/babel-top.js', $directory . '/' . $tmpname . '/assets/babel-top.js');
    @symlink('../../../babel/babel-bottom.js', $directory . '/' . $tmpname . '/assets/babel-bottom.js');
    // default support is for gh-pages
    if (is_null($domain) && isset($gitDetails->user)) {
      $domain = 'https://' . $gitDetails->user . '.github.io/' . $tmpname;
    }
    else {
      // put domain into CNAME not the github.io address if that exists
      @file_put_contents($directory . '/' . $tmpname . '/CNAME', $domain);
    }
    // load what we just created
    $this->manifest = new JSONOutlineSchema();
    // where to save it to
    $this->manifest->file = $directory . '/' . $tmpname . '/site.json';
    // start updating the schema to match this new item we got
    $this->manifest->title = $name;
    $this->manifest->location = $this->basePath . $tmpname . '/index.html';
    $this->manifest->metadata->siteName = $tmpname;
    $this->manifest->metadata->domain = $domain;
    $this->manifest->metadata->created = time();
    $this->manifest->metadata->updated = time();
    // create an initial page to make sense of what's there
    // this will double as saving our location and other updated data
    $this->addPage();
    // put this in version control :) :) :)
    $git = new Git();
    $repo = $git->create($directory . '/' . $tmpname);
    if (!isset($this->manifest->metadata->git->url) && isset($gitDetails->url)) {
      $this->gitSetRemote($gitDetails);
    }
    return $this;
  }
  /**
   * Rename a page from one location to another
   * This ensures that folders are moved but not the final index.html involved
   * It also helps secure the sites by ensuring movement is only within
   * their folder tree
   */
  public function renamePageLocation($old, $new) {
    $siteDirectory = $this->directory . '/' . $this->manifest->metadata->siteName;
    $old = str_replace('./', '', str_replace('../', '', $old));
    $new = str_replace('./', '', str_replace('../', '', $new));
    @rename(str_replace(
        '/index.html', '', $siteDirectory . '/' . $old
      ),
      str_replace(
        '/index.html', '', $siteDirectory . '/' . $new
      )
    );
  }
  /**
   * Basic wrapper to commit current changes to version control of the site
   */
  public function gitCommit($msg = 'Committed changes') {
    $git = new Git();
    $repo = $git->open($this->directory . '/' . $this->manifest->metadata->siteName);
    $repo->add('.');
    $repo->commit($msg);
    return true;
  }
  /**
   * Basic wrapper to commit current changes to version control of the site
   */
  public function gitPush() {
    $git = new Git();
    $repo = $git->open($this->directory . '/' . $this->manifest->metadata->siteName);
    $repo->add('.');
    $repo->commit($msg);
    return true;
  }

  /**
   * Basic wrapper to commit current changes to version control of the site
   * 
   * @var $git a stdClass containing repo details
   */
  public function gitSetRemote($gitDetails) {
    $git = new Git();
    $repo = $git->open($this->directory . '/' . $this->manifest->metadata->siteName);
    $repo->set_remote("origin", $gitDetails->url);
    return true;
  }
  /**
   * Add a page to the site's file system and reflect it in the outine schema.
   *
   * @var $parent JSONOutlineSchemaItem representing a parent to add this page under
   *
   * @return $page repesented as JSONOutlineSchemaItem
   */
  public function addPage($parent = NULL) {
    // draft an outline schema item
    $page = new JSONOutlineSchemaItem();
    // set a crappy default title
    $page->title = 'New page';
    if ($parent == NULL) {
      $page->parent = NULL;
      $page->indent = 0;
    }
    else {
      // set to the parent id
      $page->parent = $parent->id;
      // move it one indentation below the parent; this can be changed later if desired
      $page->indent = $parent->indent+1;
    }
    // set order to the page's count for default add to end ordering
    $page->order = count($this->manifest->items);
    // location is the html file we just copied and renamed
    $page->location = 'pages/' . $page->id . '/index.html';
    $page->metadata->created = time();
    $page->metadata->updated = time();
    $location = $this->directory . '/' . $this->manifest->metadata->siteName . '/pages/' . $page->id;
    // copy the page we use for simplicity (or later complexity if we want)
    $this->recurseCopy(HAXCMS_ROOT . '/system/boilerplate/page', $location);
    $this->manifest->addItem($page);
    $this->manifest->save();
    $this->updateStaticVersions();
    return $page;
  }
  /**
   * Update RSS / Atom feeds which are physical files
   */
  public function updateStaticVersions() {
    // rip changes to feed urls
    $rss = new FeedMe();
    $siteDirectory = $this->directory . '/' . $this->manifest->metadata->siteName . '/';
    @file_put_contents($siteDirectory . 'rss.xml', $rss->getRSSFeed($this));
    @file_put_contents($siteDirectory . 'atom.xml', $rss->getAtomFeed($this));
    // build a sitemap if we have a domain, kinda required...
    if (isset($this->manifest->metadata->domain)) {
      $domain = $this->manifest->metadata->domain;
      $generator = new \Icamys\SitemapGenerator\SitemapGenerator($domain, $siteDirectory);
      // will create also compressed (gzipped) sitemap
      $generator->createGZipFile = true;
      // determine how many urls should be put into one file
      // according to standard protocol 50000 is maximum value (see http://www.sitemaps.org/protocol.html)
      $generator->maxURLsPerSitemap = 50000;
      // sitemap file name
      $generator->sitemapFileName = "sitemap.xml";
      // sitemap index file name
      $generator->sitemapIndexFileName = "sitemap-index.xml";
      // adding url `loc`, `lastmodified`, `changefreq`, `priority`
      foreach ($this->manifest->items as $key => $item) {
        if ($item->parent == null) {
          $priority = '1.0';
        }
        else if ($item->indent == 2) {
          $priority = '0.7';
        }
        else {
          $priority = '0.5';
        }
        $generator->addUrl(
          $domain . '/' . str_replace('pages/', '', str_replace('/index.html', '', $item->location)),
          date(\DateTime::ATOM, $item->metadata->updated),          
          'daily',
          $priority
        );
      }
      // generating internally a sitemap
      $generator->createSitemap();
      // writing early generated sitemap to file
      $generator->writeSitemap();
    }
    // now generate a static list of links. This is so we can have legacy fail-back iframe mode in tact
    @file_put_contents($siteDirectory . 'legacy-outline.html', '<!DOCTYPE html><html lang="en"><head></head><body>' . $this->treeToNodes($this->manifest->items) . '</body></html>');
  }
  /**
   * Build a JOS into a tree of links recursively
   */
  private function treeToNodes($current, &$rendered = array(), $html = '') {
    $loc = '';
    foreach ($current as $item) {
      if (!array_search($item->id, $rendered)) {
        $loc .= '<li><a href="' . $item->location . '" target="content">' . $item->title . '</a>';
        array_push($rendered, $item->id);
        $children = array();
        foreach ($this->manifest->items as $child) {
          if ($child->parent == $item->id) {
            array_push($children, $child);
          }
        }
        // sort the kids
        usort($children, function($a, $b) {
          return $a->order > $b->order;
        });
        // only walk deeper if there were children for this page
        if (count($children) > 0) {
          $loc .= $this->treeToNodes($children, $rendered);
        }
        $loc .= '</li>';
      }
    }
    // make sure we aren't empty here before wrapping
    if ($loc != '') {
      $loc = '<ul>' . $loc . '</ul>';
    }
    return $html . $loc;
  }
  /**
   * Load page by unique id
   */
  public function loadNode($uuid) {
    foreach ($this->manifest->items as $item) {
      if ($item->id == $uuid) {
        return $item;
      }
    }
    return FALSE;
  }
  /**
   * Load field schema for a page
   * Field cascade always follows Core -> Deploy -> Theme -> Site
   * Anything downstream can always override upstream but no one can remove fields
   */
  public function loadNodeFieldSchema($page) {
    $fields = array(
      'configure' => array(),
      'advanced' => array()
    );
    // load core fields
    // it may seem silly but we seek to not brick any usecase so if this file is gone.. don't die
    if (file_exists(HAXCMS_ROOT . '/system/coreConfig/itemFields.json')) {
      $coreFields = json_decode(file_get_contents(HAXCMS_ROOT . '/system/coreConfig/itemFields.json'));
      $themes = array();
      foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $item) {
        $themes[$key] = $item->name;
        $themes['key'] = $key;
      }
      // this needs to be set dynamically
      foreach ($coreFields->advanced as $key => $item) {
        if ($item->property === 'theme') {
          $coreFields->advanced[$key]->options = $themes;
        }
      }
      // CORE fields
      if (isset($coreFields->configure)) {
        foreach ($coreFields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($coreFields->advanced)) {
        foreach ($coreFields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // fields can live globally in config
    if (isset($GLOBALS['HAXCMS']->config->fields)) {
      if (isset($GLOBALS['HAXCMS']->config->fields->configure)) {
        foreach ($GLOBALS['HAXCMS']->config->fields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($GLOBALS['HAXCMS']->config->fields->advanced)) {
        foreach ($GLOBALS['HAXCMS']->config->fields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // fields can live in the theme
    if (isset($this->manifest->metadata->theme->fields) && file_exists(HAXCMS_ROOT . '/build/es6/node_modules/'. $this->manifest->metadata->theme->fields)) {
      // @todo thik of how to make this less brittle
      // not a fan of pegging loading this definition to our file system's publishing structure
      $themeFields = json_decode(file_get_contents(HAXCMS_ROOT . '/build/es6/node_modules/'. $this->manifest->metadata->theme->fields));
      if (isset($themeFields->configure)) {
        foreach ($themeFields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($themeFields->advanced)) {
        foreach ($themeFields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // fields can live in the site itself
    if (isset($this->manifest->metadata->fields)) {
      if (isset($this->manifest->metadata->fields->configure)) {
        foreach ($this->manifest->metadata->fields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($this->manifest->metadata->fields->advanced)) {
        foreach ($this->manifest->metadata->fields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // core values that live outside of the fields area
    $values = array(
      'title' => $page->title,
      'location' => str_replace('pages/', '', str_replace('/index.html', '', $page->location)),
      'description' => $page->description,
      'created' => $page->metadata->created,
    );
    // now get the field data from the page
    if (isset($page->metadata->fields)) {
      foreach ($page->metadata->fields as $key => $item) {
        if ($key == 'theme') {
          $values[$key] = $item['key'];
        }
        else {
          $values[$key] = $item;
        }
      }
    }
    // response as schema and values
    $response = new stdClass();
    $response->haxSchema = $fields;
    $response->values = $values;
    return $response;
  }
  /**
   * Load field schema for a page
   * Field cascade always follows Core -> Deploy -> Theme -> Site
   * Anything downstream can always override upstream but no one can remove fields
   */
  public function loadSiteFieldSchema() {
    $fields = array(
      'configure' => array(),
      'advanced' => array()
    );
    $nodeFields = array(
    );
    // load core fields
    // it may seem silly but we seek to not brick any usecase so if this file is gone.. don't die
    if (file_exists(HAXCMS_ROOT . '/system/coreConfig/siteFields.json')) {
      $coreFields = json_decode(file_get_contents(HAXCMS_ROOT . '/system/coreConfig/siteFields.json'));
      $themes = array();
      foreach ($GLOBALS['HAXCMS']->getThemes() as $key => $item) {
        $themes[$key] = $item->name;
        $themes['key'] = $key;
      }
      // this needs to be set dynamically
      foreach ($coreFields->configure as $key => $item) {
        if ($item->property === 'theme') {
          $coreFields->configure[$key]->options = $themes;
        }
      }
      foreach ($coreFields->advanced as $key => $item) {
        if ($item->property === 'license') {
          $coreFields->advanced[$key]->options = $this->getLicenseData();
        }
      }
      // CORE fields
      if (isset($coreFields->configure)) {
        foreach ($coreFields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($coreFields->advanced)) {
        foreach ($coreFields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // fields can live globally in config
    if (isset($GLOBALS['HAXCMS']->config->siteFields)) {
      if (isset($GLOBALS['HAXCMS']->config->siteFields->configure)) {
        foreach ($GLOBALS['HAXCMS']->config->siteFields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($GLOBALS['HAXCMS']->config->siteFields->advanced)) {
        foreach ($GLOBALS['HAXCMS']->config->siteFields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // fields can live in the theme
    if (isset($this->manifest->metadata->theme->siteFields) && file_exists(HAXCMS_ROOT . '/build/es6/node_modules/'. $this->manifest->metadata->theme->siteFields)) {
      // @todo thik of how to make this less brittle
      // not a fan of pegging loading this definition to our file system's publishing structure
      $themeFields = json_decode(file_get_contents(HAXCMS_ROOT . '/build/es6/node_modules/'. $this->manifest->metadata->theme->siteFields));
      if (isset($themeFields->configure)) {
        foreach ($themeFields->configure as $item) {
          $fields['configure'][] = $item;
        }
      }
      if (isset($themeFields->advanced)) {
        foreach ($themeFields->advanced as $item) {
          $fields['advanced'][] = $item;
        }
      }
    }
    // fields can live in the site itself
    // @todo this needs to give you data differently
    if (isset($this->manifest->metadata->fields)) {
      if (isset($this->manifest->metadata->fields->configure)) {
        foreach ($this->manifest->metadata->fields->configure as $item) {
          $item->formgroup = 'configure';
          $nodeFields[] = $item;
        }
      }
      if (isset($this->manifest->metadata->fields->advanced)) {
        foreach ($this->manifest->metadata->fields->advanced as $item) {
          $item->formgroup = 'advanced';
          $nodeFields[] = $item;
        }
      }
    }
    // icon wasn't required at one point
    if (!isset($this->manifest->metadata->icon)) {
      $this->manifest->metadata->icon = '';
    }
    // core values that live outside of the fields area
    $values = array(
      'title' => $this->manifest->title,
      'author' => $this->manifest->author,
      'license' => $this->manifest->license,
      'description' => $this->manifest->description,
      'icon' => $this->manifest->metadata->icon,
      'theme' => $this->manifest->metadata->theme,
      'domain' => $this->manifest->metadata->domain,
      'image' => $this->manifest->metadata->image,
      'cssVariable' => $this->manifest->metadata->cssVariable,
      'fields' => $nodeFields,
    );
    // now get the field data from the page
    if (isset($this->manifest->metadata->fields)) {
      foreach ($this->manifest->metadata->fields as $key => $item) {
        if ($key == 'theme') {
          $values[$key] = $item['key'];
        }
        else {
          $values[$key] = $item;
        }
      }
    }
    // response as schema and values
    $response = new stdClass();
    $response->haxSchema = $fields;
    $response->values = $values;
    return $response;
  }
    /**
   * License data for common open license
   */
  public function getLicenseData($type = 'select') {
    $list = array(
      "by" => array(
        'name' => "Creative Commons: Attribution",
        'link' => "https://creativecommons.org/licenses/by/4.0/",
        'image' => "https://i.creativecommons.org/l/by/4.0/88x31.png"
      ),
      "by-sa" => array(
        'name' => "Creative Commons: Attribution Share a like",
        'link' => "https://creativecommons.org/licenses/by-sa/4.0/",
        'image' => "https://i.creativecommons.org/l/by-sa/4.0/88x31.png"
      ),
      "by-nd" => array(
        'name' => "Creative Commons: Attribution No derivatives",
        'link' => "https://creativecommons.org/licenses/by-nd/4.0/",
        'image' => "https://i.creativecommons.org/l/by-nd/4.0/88x31.png"
      ),
      "by-nc" => array(
        'name' => "Creative Commons: Attribution non-commercial",
        'link' => "https://creativecommons.org/licenses/by-nc/4.0/",
        'image' => "https://i.creativecommons.org/l/by-nc/4.0/88x31.png"
      ),
      "by-nc-sa" => array(
        'name' => "Creative Commons: Attribution non-commercial share a like",
        'link' => "https://creativecommons.org/licenses/by-nc-sa/4.0/",
        'image' => "https://i.creativecommons.org/l/by-nc-sa/4.0/88x31.png"
      ),
      "by-nc-nd" => array(
        'name' => "Creative Commons: Attribution Non-commercial No derivatives",
        'link' => "https://creativecommons.org/licenses/by-nc-nd/4.0/",
        'image' => "https://i.creativecommons.org/l/by-nc-nd/4.0/88x31.png"
      ),
    );
    $data = array();
    if ($type == 'select') {
      foreach ($list as $key => $item) {
        $data[$key] = $item['name'];
      }
    }
    return $data;
  }
  /**
   * Update page in the manifest list of items. useful if updating some
   * data about an existing entry.
   * @return JSONOutlineSchemaItem or FALSE
   */
  public function updateNode($page) {
    foreach ($this->manifest->items as $key => $item) {
      if ($item->id === $page->id) {
        $this->manifest->items[$key] = $page;
        $this->manifest->save(FALSE);
        $this->updateStaticVersions();
        return $page;
      }
    }
    return FALSE;
  }
  /**
   * Delete a page from the manifest
   * @return JSONOutlineSchemaItem or FALSE
   */
  public function deleteNode($page) {
    foreach ($this->manifest->items as $key => $item) {
      if ($item->id === $page->id) {
        unset($this->manifest->items[$key]);
        $this->manifest->save(FALSE);
        $this->updateStaticVersions();
        return TRUE;
      }
    }
    return FALSE;
  }
  /**
   * Change the directory this site is located in
   */
  public function changeName($new) {
    $new = str_replace('./', '', str_replace('../', '', $new));
    // attempt to shift it on the file system
    if ($new != $this->manifest->metadata->siteName) {
      $this->manifest->metadata->siteName = $new;
      return @rename($this->manifest->metadata->siteName, $new);
    }
  }
  /**
   * Test and ensure the name being returned is a location currently unused
   */
  public function getUniqueLocationName($location) {
    $siteDirectory = $this->directory . '/' . $this->manifest->metadata->siteName;
    $loop = 0;
    $original = $location;
    while (file_exists($siteDirectory . '/pages/' . $location . '/index.html')) {
      $loop++;
      $location = $original . '-' . $loop;
    }
    return $location;
  }
  /**
   * Recursive copy to rename high level but copy all files
   */
  public function recurseCopy($src, $dst) {
    $dir = opendir($src);
    // see if we can make the directory to start off
    if (!is_dir($dst) && @mkdir($dst, 0777, TRUE)) {
      while (FALSE !== ( $file = readdir($dir)) ) {
        if (($file != '.') && ($file != '..')) {
          if (is_dir($src . '/' . $file)) {
            $this->recurseCopy($src . '/' . $file, $dst . '/' . $file);
          }
          else {
            copy($src . '/' . $file, $dst . '/' . $file);
          }
        }
      }
    }
    else {
      return FALSE;
    }
    closedir($dir);
  }
}