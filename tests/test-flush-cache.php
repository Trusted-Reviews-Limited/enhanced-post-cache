<?php

class NeedFlushTest extends WP_UnitTestCase
{
    public function setUp()
    {
        parent::setUp();
        global $enhanced_post_cache_object;
        $this->obj = new Enhanced_Post_Cache;
        $this->query = new WP_Query();
    }

    public function test_set_dont_flush_cache()
    {
        $salt = $this->obj->cache_salt;
        $this->obj->dont_clear_advanced_post_cache();
        $this->obj->flush_cache();
        $this->assertSame($salt, $this->obj->cache_salt);
    }

    public function test_is_preview_page_dont_flush_cache()
    {
        $salt = $this->obj->cache_salt;
        $_POST['wp-preview'] = 'dopreview';
        $this->obj->flush_cache();
        $this->assertsame($salt, $this->obj->cache_salt);

        unset($_POST['wp-preview']);
    }

    public function test_is_revision_dont_flush_cache()
    {
        $post = $this->factory->post->create_and_get();
        wp_update_post(
            array(
                'post_status' => 'draft',
                'post_title' => 'some-post',
                'post_type' => 'post',
                'post_content' => 'some_content',
                'ID' => $post->ID,
            )
        );

        $salt = $this->obj->cache_salt;

        $revisions = wp_get_post_revisions($post->ID);
        $revision = array_shift( $revisions );
        $this->obj->clean_post_cache($revision->ID, $revision);
        $this->assertsame($salt, $this->obj->cache_salt);
    }

    public function test_is_autosave_dont_flush_cache()
    {
        $post = $this->factory->post->create_and_get();
        $revision_id = _wp_put_post_revision($post, true);
        $salt = $this->obj->cache_salt;

        $revision = get_post($revision_id);
        $this->obj->clean_post_cache($revision->ID, $revision);
        $this->assertsame($salt, $this->obj->cache_salt);
    }
}
