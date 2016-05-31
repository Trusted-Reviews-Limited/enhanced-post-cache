<?php

class SaltTest extends WP_UnitTestCase
{
    public function setUp()
    {
        parent::setUp();
        global $enhanced_post_cache_object;
        $this->obj = new Enhanced_Post_Cache;
    }

    public function test_force_new_salt_by_adding_post()
    {
        $this->factory->post->create_many(10);

        $first_run = (new WP_Query)->query(['posts_per_page' => -1]);
        $this->assertFalse($this->obj->all_post_ids);
        $salt = $this->obj->cache_salt;

        $posts = $this->factory->post->create_many(10);
        array_map('clean_post_cache', $posts);

        $second_run = (new WP_Query)->query(['posts_per_page' => -1]);

        $this->assertNotEquals($salt, $this->obj->cache_salt);
        $this->assertFalse($this->obj->all_post_ids);
    }

    public function test_force_new_salt_by_changing_blog()
    {
        $new_blog = $this->factory->blog->create();

        $this->factory->post->create_many(10);
        $first_run = (new WP_Query)->query([]);
        $this->assertSame($this->obj->all_post_ids, false);
        $salt = $this->obj->cache_salt;

        switch_to_blog($new_blog);

        $second_run = (new WP_Query)->query([]);
        $this->assertFalse($this->obj->all_post_ids);
        $this->assertNotSame($salt, $this->obj->cache_salt);
    }
}
