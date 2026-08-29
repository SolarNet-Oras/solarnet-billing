<?php

namespace Tests\Unit;

use App\Models\FacebookPagePostDraft;
use Tests\TestCase;

class FacebookPagePostDraftImageTest extends TestCase
{
    public function test_post_draft_reports_a_private_image_without_exposing_its_storage_path(): void
    {
        $draft = new FacebookPagePostDraft([
            'image_path' => 'facebook-page-post-images/posts/example.png',
            'image_mime' => 'image/png',
        ]);

        $data = $draft->toAutomationArray();

        $this->assertTrue($data['has_image']);
        $this->assertArrayNotHasKey('image_path', $data);
        $this->assertArrayNotHasKey('image_mime', $data);
    }
}
