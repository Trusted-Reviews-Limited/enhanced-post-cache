<?php

class FlushCacheTest extends WP_UnitTestCase
{
    public function setUp()
    {
        parent::setUp();
        global $advanced_post_cache_object;
        $this->obj = $advanced_post_cache_object;
        $this->query = new WP_Query();
        wp_cache_flush();
    }

    public function test_post_cache()
    {
        $this->factory->post->create_many(10);
        $first_run = $this->query->query([]);
        $this->assertSame($this->obj->all_post_ids, false);

        $second_run = $this->query->query([]);
        $this->assertTrue(is_array($this->obj->all_post_ids));
        $this->assertEquals($first_run, $second_run);
    }

    public function test_count_returned_posts()
    {
        $this->factory->post->create_many(5);

        $first_run = $this->query->query(['posts_per_page' => 2]);
        $this->assertSame($this->obj->all_post_ids, false);
        $this->assertSame(5, (int) $this->query->found_posts);
        $this->assertSame(2, $this->query->post_count);
        $this->assertsame(3, (int) $this->query->max_num_pages);

        $second_run = $this->query->query(['posts_per_page' => 2]);
        $this->assertTrue(is_array($this->obj->all_post_ids));
        $this->assertEquals($first_run, $second_run);
        $this->assertSame(5, (int) $this->query->found_posts);
        $this->assertSame(2, $this->query->post_count);
        $this->assertsame(3, (int) $this->query->max_num_pages);

        $third_run = $this->query->query(['posts_per_page' => 3]);
        $this->assertSame($this->obj->all_post_ids, false);
        $this->assertSame(5, (int) $this->query->found_posts);
        $this->assertSame(3, $this->query->post_count);
        $this->assertsame(2, (int) $this->query->max_num_pages);

        $this->factory->post->create_many(5);

        $last_run = $this->query->query(['posts_per_page' => 3]);
        $this->assertSame(10, (int) $this->query->found_posts);
        $this->assertSame(3, $this->query->post_count);
        $this->assertsame(4, (int) $this->query->max_num_pages);
        $this->assertNotEquals($third_run, $last_run);
    }

    public function test_post_flushing_cache()
    {
        $this->factory->post->create_many(10);
        $first_run = $this->query->query([]);
        $this->assertSame($this->obj->all_post_ids, false);

        $post_id = $this->factory->post->create();
        clean_post_cache($post_id);

        $second_run = $this->query->query([]);
        $this->assertSame($this->obj->all_post_ids, false);
        $this->assertNotEquals($first_run, $second_run);

        $third_run = $this->query->query(['p' => $post_id]);
        $this->assertSame($this->obj->all_post_ids, false);
        $this->assertNotEquals($second_run, $third_run);
    }

    public function test_term_flushing_cache()
    {
        $post_id = $this->factory->post->create();
        $term_id = $this->factory->term->create(['banana', 'post_tag']);
        wp_set_post_terms($post_id, 'banana');

        $first_run = $this->query->query([]);
        $this->assertSame($this->obj->all_post_ids, false);

        clean_term_cache($term_id);

        $second_run = $this->query->query([]);
        $this->assertSame($this->obj->all_post_ids, false);
    }
}
