<?php

declare(strict_types=1);

namespace FrankenWP\Watermark;

use GdImage;
use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;
use WP_Error;

final class WatermarkPlugin
{
    private const OPTION_KEY = 'frankenwp_watermark_options';
    private const SETTINGS_GROUP = 'frankenwp_watermark';
    private const MENU_SLUG = 'frankenwp-watermark';
    private const CAPABILITY = 'manage_options';
    private const TEXT_DOMAIN = 'auto-watermark';
    private const PREVIEW_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WnRkX8AAAAASUVORK5CYII=';

    private const DEFAULTS = [
        'enabled' => true,
        'type' => 'text',
        'text' => 'waq3.net',
        'font_size' => 24,
        'opacity' => 0.18,
        'angle' => -30,
        'spacing_x' => 220,
        'spacing_y' => 180,
        'color' => '#ffffff',
        'image_id' => 0,
        'image_scale' => 18,
    ];

    private string $pluginFile;

    public static function boot(string $pluginFile): void
    {
        $instance = new self($pluginFile);
        $instance->register();
    }

    private function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }

    private function register(): void
    {
        $this->debug('plugin_boot', [
            'plugin' => plugin_basename($this->pluginFile),
            'imagick' => extension_loaded('imagick'),
            'gd' => extension_loaded('gd'),
        ]);

        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_filter('wp_handle_upload', [$this, 'handleDocumentUpload'], 20);
        add_filter('wp_generate_attachment_metadata', [$this, 'watermarkAttachmentMetadata'], 20, 2);
    }

    public function registerSettings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeOptions'],
                'default' => self::DEFAULTS,
                'show_in_rest' => false,
            ]
        );
    }

    public function registerAdminPage(): void
    {
        add_options_page(
            __('Watermark', self::TEXT_DOMAIN),
            __('Watermark', self::TEXT_DOMAIN),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderAdminPage']
        );
    }

    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'auto-watermark-admin',
            plugins_url('assets/admin.css', $this->pluginFile),
            [],
            '0.1.0'
        );

        wp_enqueue_script(
            'auto-watermark-admin',
            plugins_url('assets/admin.js', $this->pluginFile),
            [],
            '0.1.0',
            true
        );

        wp_localize_script(
            'auto-watermark-admin',
            'autoWatermarkAdmin',
            [
                'previewImage' => self::PREVIEW_IMAGE,
                'mediaTitle' => __('Select watermark image', self::TEXT_DOMAIN),
                'mediaButton' => __('Use this image', self::TEXT_DOMAIN),
                'removeImage' => __('Remove image', self::TEXT_DOMAIN),
                'defaults' => $this->getOptions(),
            ]
        );
    }

    public function renderAdminPage(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $options = $this->getOptions();
        $imageUrl = $this->getWatermarkImageUrl($options);
        $supportsImagick = extension_loaded('imagick');
        $supportsGd = extension_loaded('gd');
        ?>
        <div class="wrap auto-watermark-admin">
            <h1><?php echo esc_html(__('Watermark', self::TEXT_DOMAIN)); ?></h1>

            <?php if (! $supportsImagick && ! $supportsGd) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(__('The server is missing both Imagick and GD, so watermarking cannot run.', self::TEXT_DOMAIN)); ?></p></div>
            <?php elseif (! $supportsImagick) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html(__('PDF watermarking is unavailable because Imagick is not loaded.', self::TEXT_DOMAIN)); ?></p></div>
            <?php endif; ?>

            <p><?php echo esc_html(__('Images and generated thumbnails are watermarked automatically. PDF watermarking requires Imagick and Ghostscript support in the container.', self::TEXT_DOMAIN)); ?></p>

            <div class="auto-watermark-layout">
                <form action="options.php" method="post" class="auto-watermark-form">
                    <?php settings_fields(self::SETTINGS_GROUP); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php $this->renderCheckboxField('enabled', __('Enable watermarking', self::TEXT_DOMAIN), __('Apply watermark to new uploads and generated image sizes.', self::TEXT_DOMAIN), $options); ?>
                            <?php $this->renderSelectField('type', __('Watermark type', self::TEXT_DOMAIN), [
                                'text' => __('Text only', self::TEXT_DOMAIN),
                                'image' => __('Image only', self::TEXT_DOMAIN),
                                'both' => __('Text and image', self::TEXT_DOMAIN),
                            ], __('Choose whether to tile text, a logo image, or both.', self::TEXT_DOMAIN), $options); ?>
                            <?php $this->renderTextField('text', __('Watermark text', self::TEXT_DOMAIN), __('The text repeated across uploaded files.', self::TEXT_DOMAIN), $options); ?>
                            <?php $this->renderNumberField('font_size', __('Font size', self::TEXT_DOMAIN), __('Pixel size used when drawing the watermark text.', self::TEXT_DOMAIN), $options, '8', '200', '1'); ?>
                            <?php $this->renderNumberField('opacity', __('Opacity', self::TEXT_DOMAIN), __('A number between 0.05 and 1.00.', self::TEXT_DOMAIN), $options, '0.05', '1', '0.01'); ?>
                            <?php $this->renderNumberField('angle', __('Angle', self::TEXT_DOMAIN), __('Rotation in degrees.', self::TEXT_DOMAIN), $options, '-180', '180', '1'); ?>
                            <?php $this->renderNumberField('spacing_x', __('Horizontal spacing', self::TEXT_DOMAIN), __('Distance in pixels between watermark repetitions.', self::TEXT_DOMAIN), $options, '40', '2000', '1'); ?>
                            <?php $this->renderNumberField('spacing_y', __('Vertical spacing', self::TEXT_DOMAIN), __('Distance in pixels between watermark repetitions.', self::TEXT_DOMAIN), $options, '40', '2000', '1'); ?>
                            <?php $this->renderTextField('color', __('Text color', self::TEXT_DOMAIN), __('Hex color used for text watermark rendering.', self::TEXT_DOMAIN), $options, 'regular-text auto-watermark-color'); ?>
                            <?php $this->renderMediaField('image_id', __('Watermark image', self::TEXT_DOMAIN), __('Upload or select a transparent PNG logo to tile across files.', self::TEXT_DOMAIN), $options, $imageUrl); ?>
                            <?php $this->renderNumberField('image_scale', __('Image scale', self::TEXT_DOMAIN), __('Approximate width of each tiled logo as a percentage of the target image width.', self::TEXT_DOMAIN), $options, '5', '100', '1'); ?>
                        </tbody>
                    </table>

                    <?php submit_button(__('Save Changes', self::TEXT_DOMAIN)); ?>
                </form>

                <aside class="auto-watermark-preview-panel">
                    <h2><?php echo esc_html(__('Live preview', self::TEXT_DOMAIN)); ?></h2>
                    <p><?php echo esc_html(__('Preview updates automatically while you change settings. Saving is only required to apply the new configuration to future uploads.', self::TEXT_DOMAIN)); ?></p>
                    <canvas id="auto-watermark-preview" width="720" height="960" aria-label="<?php echo esc_attr(__('Watermark preview canvas', self::TEXT_DOMAIN)); ?>"></canvas>
                </aside>
            </div>
        </div>
        <?php
    }

    public function sanitizeOptions(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $type = (string) ($value['type'] ?? self::DEFAULTS['type']);

        if (! in_array($type, ['text', 'image', 'both'], true)) {
            $type = self::DEFAULTS['type'];
        }

        return [
            'enabled' => ! empty($value['enabled']),
            'type' => $type,
            'text' => sanitize_text_field((string) ($value['text'] ?? self::DEFAULTS['text'])),
            'font_size' => max(8, min(200, absint($value['font_size'] ?? self::DEFAULTS['font_size']))),
            'opacity' => max(0.05, min(1.0, (float) ($value['opacity'] ?? self::DEFAULTS['opacity']))),
            'angle' => max(-180, min(180, (float) ($value['angle'] ?? self::DEFAULTS['angle']))),
            'spacing_x' => max(40, min(2000, absint($value['spacing_x'] ?? self::DEFAULTS['spacing_x']))),
            'spacing_y' => max(40, min(2000, absint($value['spacing_y'] ?? self::DEFAULTS['spacing_y']))),
            'color' => $this->sanitizeColor((string) ($value['color'] ?? self::DEFAULTS['color'])),
            'image_id' => absint($value['image_id'] ?? self::DEFAULTS['image_id']),
            'image_scale' => max(5, min(100, absint($value['image_scale'] ?? self::DEFAULTS['image_scale']))),
        ];
    }

    public function handleDocumentUpload(array $upload): array
    {
        $options = $this->getOptions();

        $this->debug('handle_document_upload_enter', [
            'enabled' => $options['enabled'],
            'type' => $options['type'],
            'upload' => $this->summarizeUpload($upload),
        ]);

        if (! $options['enabled']) {
            $this->debug('handle_document_upload_skip_disabled');
            return $upload;
        }

        if (! empty($upload['error']) || empty($upload['file']) || ! is_string($upload['file'])) {
            $this->debug('handle_document_upload_skip_invalid_upload', [
                'upload' => $this->summarizeUpload($upload),
            ]);
            return $upload;
        }

        $path = $upload['file'];
        $mime = (string) ($upload['type'] ?? wp_check_filetype($path)['type'] ?? '');

        if ($mime !== 'application/pdf' || ! extension_loaded('imagick') || ! is_file($path) || ! is_writable($path)) {
            $this->debug('handle_document_upload_skip_not_supported', [
                'path' => $path,
                'mime' => $mime,
                'imagick' => extension_loaded('imagick'),
                'is_file' => is_file($path),
                'is_writable' => is_writable($path),
            ]);
            return $upload;
        }

        $result = $this->applyPdfWatermark($path, $options);

        if (is_wp_error($result)) {
            $this->logError($result);
        } else {
            $this->debug('handle_document_upload_success', [
                'path' => $path,
                'mime' => $mime,
            ]);
        }

        return $upload;
    }

    public function watermarkAttachmentMetadata(array $metadata, int $attachmentId): array
    {
        $options = $this->getOptions();

        $this->debug('watermark_attachment_metadata_enter', [
            'attachment_id' => $attachmentId,
            'enabled' => $options['enabled'],
            'type' => $options['type'],
            'metadata_file' => $metadata['file'] ?? null,
            'sizes_count' => isset($metadata['sizes']) && is_array($metadata['sizes']) ? count($metadata['sizes']) : 0,
        ]);

        if (! $options['enabled']) {
            $this->debug('watermark_attachment_metadata_skip_disabled', [
                'attachment_id' => $attachmentId,
            ]);
            return $metadata;
        }

        $mime = (string) get_post_mime_type($attachmentId);
        if (! str_starts_with($mime, 'image/')) {
            $this->debug('watermark_attachment_metadata_skip_non_image', [
                'attachment_id' => $attachmentId,
                'mime' => $mime,
            ]);
            return $metadata;
        }

        $originalPath = get_attached_file($attachmentId);
        if (is_string($originalPath) && $originalPath !== '') {
            $this->debug('watermark_attachment_metadata_original', [
                'attachment_id' => $attachmentId,
                'path' => $originalPath,
                'mime' => $mime,
            ]);
            $result = $this->applyRasterWatermark($originalPath, $mime, $options);
            if (is_wp_error($result)) {
                $this->logError($result);
            } else {
                $this->debug('watermark_attachment_metadata_original_success', [
                    'attachment_id' => $attachmentId,
                    'path' => $originalPath,
                ]);
            }
        } else {
            $this->debug('watermark_attachment_metadata_missing_original', [
                'attachment_id' => $attachmentId,
            ]);
        }

        if (! empty($metadata['sizes']) && is_array($metadata['sizes']) && is_string($originalPath) && $originalPath !== '') {
            $directory = wp_normalize_path((string) dirname($originalPath));

            foreach ($metadata['sizes'] as $size) {
                if (! is_array($size) || empty($size['file']) || ! is_string($size['file'])) {
                    continue;
                }

                $sizePath = $directory . '/' . ltrim(wp_normalize_path($size['file']), '/');
                $sizeMime = (string) ($size['mime-type'] ?? $mime);
                $this->debug('watermark_attachment_metadata_size', [
                    'attachment_id' => $attachmentId,
                    'path' => $sizePath,
                    'mime' => $sizeMime,
                ]);
                $result = $this->applyRasterWatermark($sizePath, $sizeMime, $options);

                if (is_wp_error($result)) {
                    $this->logError($result);
                } else {
                    $this->debug('watermark_attachment_metadata_size_success', [
                        'attachment_id' => $attachmentId,
                        'path' => $sizePath,
                    ]);
                }
            }
        }

        return $metadata;
    }

    private function getOptions(): array
    {
        $options = get_option(self::OPTION_KEY, []);

        return array_merge(self::DEFAULTS, is_array($options) ? $options : []);
    }

    private function renderCheckboxField(string $key, string $label, string $description, array $options): void
    {
        $name = $this->fieldName($key);
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked((bool) $options[$key]); ?> data-auto-watermark-field="<?php echo esc_attr($key); ?>">
                    <?php echo esc_html($description); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    private function renderTextField(string $key, string $label, string $description, array $options, string $class = 'regular-text'): void
    {
        $name = $this->fieldName($key);
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input id="<?php echo esc_attr($key); ?>" class="<?php echo esc_attr($class); ?>" type="text" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $options[$key]); ?>" data-auto-watermark-field="<?php echo esc_attr($key); ?>">
                <p class="description"><?php echo esc_html($description); ?></p>
            </td>
        </tr>
        <?php
    }

    private function renderNumberField(string $key, string $label, string $description, array $options, string $min, string $max, string $step): void
    {
        $name = $this->fieldName($key);
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input id="<?php echo esc_attr($key); ?>" class="small-text" type="number" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" step="<?php echo esc_attr($step); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $options[$key]); ?>" data-auto-watermark-field="<?php echo esc_attr($key); ?>">
                <p class="description"><?php echo esc_html($description); ?></p>
            </td>
        </tr>
        <?php
    }

    private function renderSelectField(string $key, string $label, array $choices, string $description, array $options): void
    {
        $name = $this->fieldName($key);
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>" data-auto-watermark-field="<?php echo esc_attr($key); ?>">
                    <?php foreach ($choices as $value => $choiceLabel) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected((string) $options[$key], (string) $value); ?>><?php echo esc_html($choiceLabel); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php echo esc_html($description); ?></p>
            </td>
        </tr>
        <?php
    }

    private function renderMediaField(string $key, string $label, string $description, array $options, string $imageUrl): void
    {
        $name = $this->fieldName($key);
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <input type="hidden" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $options[$key]); ?>" data-auto-watermark-field="<?php echo esc_attr($key); ?>">
                <input type="hidden" id="<?php echo esc_attr($key); ?>_url" value="<?php echo esc_url($imageUrl); ?>" data-auto-watermark-image-url>
                <div class="auto-watermark-media-preview <?php echo $imageUrl === '' ? 'is-empty' : ''; ?>">
                    <img src="<?php echo esc_url($imageUrl === '' ? self::PREVIEW_IMAGE : $imageUrl); ?>" alt="<?php echo esc_attr(__('Watermark image preview', self::TEXT_DOMAIN)); ?>">
                </div>
                <p>
                    <button type="button" class="button" data-auto-watermark-select-image><?php echo esc_html(__('Select image', self::TEXT_DOMAIN)); ?></button>
                    <button type="button" class="button button-secondary" data-auto-watermark-remove-image><?php echo esc_html(__('Remove image', self::TEXT_DOMAIN)); ?></button>
                </p>
                <p class="description"><?php echo esc_html($description); ?></p>
            </td>
        </tr>
        <?php
    }

    private function fieldName(string $key): string
    {
        return sprintf('%s[%s]', self::OPTION_KEY, $key);
    }

    private function sanitizeColor(string $color): string
    {
        $color = trim($color);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
            return $color;
        }

        return self::DEFAULTS['color'];
    }

    private function getWatermarkImageUrl(array $options): string
    {
        $imageId = absint($options['image_id'] ?? 0);
        if ($imageId <= 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($imageId, 'medium');

        return is_string($url) ? $url : '';
    }

    private function getWatermarkImagePath(array $options): ?string
    {
        $imageId = absint($options['image_id'] ?? 0);
        if ($imageId <= 0) {
            return null;
        }

        $path = get_attached_file($imageId);

        return is_string($path) && is_readable($path) ? $path : null;
    }

    private function applyRasterWatermark(string $path, string $mime, array $options): true|WP_Error
    {
        $this->debug('apply_raster_watermark_enter', [
            'path' => $path,
            'mime' => $mime,
            'type' => $options['type'] ?? null,
            'imagick' => extension_loaded('imagick'),
            'file_exists' => is_file($path),
            'is_writable' => is_writable($path),
        ]);

        if (! is_file($path) || ! is_writable($path)) {
            return new WP_Error('auto_watermark_target_unwritable', __('The upload target is not writable.', self::TEXT_DOMAIN));
        }

        if (extension_loaded('imagick')) {
            return $this->applyImageWatermarkWithImagick($path, $options);
        }

        return $this->applyImageWatermarkWithGd($path, $mime, $options);
    }

    private function applyImageWatermarkWithImagick(string $path, array $options): true|WP_Error
    {
        try {
            $image = new Imagick($path);
            $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
            $this->debug('apply_image_watermark_imagick_opened', [
                'path' => $path,
                'width' => $image->getImageWidth(),
                'height' => $image->getImageHeight(),
                'frames' => $image->getNumberImages(),
            ]);

            foreach ($image as $frame) {
                $this->applyImagickWatermarkLayers($frame, $options);
                $frame->setImagePage(0, 0, 0, 0);
            }

            $image->writeImages($path, true);
            $image->clear();
            $image->destroy();

            $this->debug('apply_image_watermark_imagick_written', [
                'path' => $path,
            ]);

            return true;
        } catch (ImagickException $exception) {
            return new WP_Error('auto_watermark_imagick_image', $exception->getMessage());
        }
    }

    private function applyPdfWatermark(string $path, array $options): true|WP_Error
    {
        try {
            $document = new Imagick();
            $document->readImage($path);
            $this->debug('apply_pdf_watermark_opened', [
                'path' => $path,
                'pages' => $document->getNumberImages(),
            ]);

            foreach ($document as $page) {
                $this->applyImagickWatermarkLayers($page, $options);
                $page->setImageFormat('pdf');
                $page->setImagePage(0, 0, 0, 0);
            }

            $document->writeImages($path, true);
            $document->clear();
            $document->destroy();

            $this->debug('apply_pdf_watermark_written', [
                'path' => $path,
            ]);

            return true;
        } catch (ImagickException $exception) {
            return new WP_Error('auto_watermark_imagick_pdf', $exception->getMessage());
        }
    }

    private function applyImagickWatermarkLayers(Imagick $image, array $options): void
    {
        if ($this->shouldRenderText($options)) {
            $draw = $this->buildImagickDraw($options);
            $this->drawTiledTextImagick($image, $draw, $options);
        }

        if ($this->shouldRenderImage($options)) {
            $logoPath = $this->getWatermarkImagePath($options);
            if ($logoPath !== null) {
                $this->drawTiledImageImagick($image, $logoPath, $options);
            }
        }
    }

    private function buildImagickDraw(array $options): ImagickDraw
    {
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel($this->hexToRgba($options['color'], (float) $options['opacity'])));
        $draw->setGravity(Imagick::GRAVITY_NORTHWEST);
        $draw->setFontSize((float) $options['font_size']);
        $draw->setTextEncoding('UTF-8');

        $fontPath = $this->findFontPath((string) $options['text']);
        if ($fontPath !== null) {
            $draw->setFont($fontPath);
        }

        if (defined('Imagick::DIRECTION_RIGHT_TO_LEFT') && $this->containsArabic((string) $options['text'])) {
            $draw->setTextDirection(Imagick::DIRECTION_RIGHT_TO_LEFT);
        } elseif (defined('Imagick::DIRECTION_LEFT_TO_RIGHT')) {
            $draw->setTextDirection(Imagick::DIRECTION_LEFT_TO_RIGHT);
        }

        return $draw;
    }

    private function drawTiledTextImagick(Imagick $image, ImagickDraw $draw, array $options): void
    {
        try {
            $tile = $this->buildImagickTextTile($draw, $options);
            [$spacingX, $spacingY] = $this->computeSpacing($options, $tile->getImageWidth(), $tile->getImageHeight());
            [$width, $height] = [$image->getImageWidth(), $image->getImageHeight()];

            for ($y = -$height; $y < $height * 2; $y += $spacingY) {
                for ($x = -$width; $x < $width * 2; $x += $spacingX) {
                    $image->compositeImage($tile, Imagick::COMPOSITE_OVER, $x, $y);
                }
            }

            $tile->clear();
            $tile->destroy();
        } catch (ImagickException $exception) {
            $this->logError(new WP_Error('auto_watermark_imagick_text_tile', $exception->getMessage()));
        }
    }

    private function buildImagickTextTile(ImagickDraw $draw, array $options): Imagick
    {
        $probe = new Imagick();
        $probe->newImage(1, 1, new ImagickPixel('transparent'));
        $probe->setImageFormat('png');
        $metrics = $probe->queryFontMetrics($draw, (string) $options['text']);
        $textWidth = max(1, (int) ceil($metrics['textWidth'] ?? ($options['font_size'] * max(1, mb_strlen((string) $options['text'])))));
        $textHeight = max(1, (int) ceil($metrics['textHeight'] ?? ($options['font_size'] * 1.4)));
        $descender = max(0, (int) ceil(abs((float) ($metrics['descender'] ?? 0))));
        $padding = max(20, (int) ceil((float) $options['font_size'] * 0.8));

        $base = new Imagick();
        $base->newImage($textWidth + ($padding * 2), $textHeight + ($padding * 2) + $descender, new ImagickPixel('transparent'));
        $base->setImageFormat('png');
        $base->annotateImage($draw, (float) $padding, (float) ($padding + $textHeight), 0.0, (string) $options['text']);

        $rotated = clone $base;
        $rotated->rotateImage(new ImagickPixel('transparent'), (float) $options['angle']);
        $rotated->trimImage(0);
        $rotated->setImagePage(0, 0, 0, 0);

        $base->clear();
        $base->destroy();
        $probe->clear();
        $probe->destroy();

        return $rotated;
    }

    private function drawTiledImageImagick(Imagick $image, string $logoPath, array $options): void
    {
        try {
            $logo = new Imagick($logoPath);
            $logo->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

            $targetWidth = max(24, (int) round($image->getImageWidth() * ((int) $options['image_scale'] / 100)));
            $logo->resizeImage($targetWidth, 0, Imagick::FILTER_LANCZOS, 1, true);
            $logo->evaluateImage(Imagick::EVALUATE_MULTIPLY, (float) $options['opacity'], Imagick::CHANNEL_ALPHA);

            [$spacingX, $spacingY] = $this->computeSpacing($options, $logo->getImageWidth(), $logo->getImageHeight());
            [$width, $height] = [$image->getImageWidth(), $image->getImageHeight()];

            for ($y = -$height; $y < $height * 2; $y += $spacingY) {
                for ($x = -$width; $x < $width * 2; $x += $spacingX) {
                    $image->compositeImage($logo, Imagick::COMPOSITE_OVER, $x, $y);
                }
            }

            $logo->clear();
            $logo->destroy();
        } catch (ImagickException $exception) {
            $this->logError(new WP_Error('auto_watermark_imagick_logo', $exception->getMessage()));
        }
    }

    private function applyImageWatermarkWithGd(string $path, string $mime, array $options): true|WP_Error
    {
        if (! function_exists('imagecreatetruecolor')) {
            return new WP_Error('auto_watermark_gd_missing', __('GD is not available.', self::TEXT_DOMAIN));
        }

        $image = $this->openGdImage($path, $mime);
        if (! $image instanceof GdImage) {
            return new WP_Error('auto_watermark_gd_open', sprintf(__('Unsupported image format for GD: %s', self::TEXT_DOMAIN), $mime));
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        if ($this->shouldRenderText($options)) {
            $result = $this->drawTiledTextGd($image, $options);
            if (is_wp_error($result)) {
                imagedestroy($image);
                return $result;
            }
        }

        if ($this->shouldRenderImage($options)) {
            $result = $this->drawTiledImageGd($image, $options);
            if (is_wp_error($result)) {
                imagedestroy($image);
                return $result;
            }
        }

        $written = match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, 90),
            'image/png' => imagepng($image, $path),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $path, 90) : false,
            default => false,
        };

        imagedestroy($image);

        return $written ? true : new WP_Error('auto_watermark_gd_write', __('Unable to write the watermarked image.', self::TEXT_DOMAIN));
    }

    private function drawTiledTextGd(GdImage $image, array $options): true|WP_Error
    {
        $fontPath = $this->findFontPath((string) $options['text']);
        if ($fontPath === null || ! function_exists('imagettfbbox') || ! function_exists('imagettftext')) {
            return new WP_Error('auto_watermark_gd_font', __('No TrueType font is available for GD watermark rendering.', self::TEXT_DOMAIN));
        }

        $tile = $this->buildGdTextTile($fontPath, $options);
        if (is_wp_error($tile)) {
            return $tile;
        }

        [$spacingX, $spacingY] = $this->computeSpacing($options, imagesx($tile), imagesy($tile));
        [$width, $height] = [imagesx($image), imagesy($image)];

        for ($y = -$height; $y < $height * 2; $y += $spacingY) {
            for ($x = -$width; $x < $width * 2; $x += $spacingX) {
                imagecopy($image, $tile, $x, $y, 0, 0, imagesx($tile), imagesy($tile));
            }
        }

        imagedestroy($tile);

        return true;
    }

    private function buildGdTextTile(string $fontPath, array $options): GdImage|WP_Error
    {
        $bbox = imagettfbbox((float) $options['font_size'], 0.0, $fontPath, (string) $options['text']);

        if (! is_array($bbox)) {
            return new WP_Error('auto_watermark_gd_bbox', __('Unable to measure watermark text.', self::TEXT_DOMAIN));
        }

        $textWidth = max(1, abs($bbox[2] - $bbox[0]));
        $textHeight = max(1, abs($bbox[7] - $bbox[1]));
        $padding = max(20, (int) ceil((float) $options['font_size'] * 0.8));
        $tileWidth = $textWidth + ($padding * 2);
        $tileHeight = $textHeight + ($padding * 2);
        $tile = imagecreatetruecolor($tileWidth, $tileHeight);

        if (! $tile instanceof GdImage) {
            return new WP_Error('auto_watermark_gd_tile', __('Unable to create watermark tile image.', self::TEXT_DOMAIN));
        }

        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        $transparent = imagecolorallocatealpha($tile, 0, 0, 0, 127);
        imagefill($tile, 0, 0, $transparent);

        [$red, $green, $blue] = $this->hexToRgb($options['color']);
        $alpha = (int) round((1 - (float) $options['opacity']) * 127);
        $color = imagecolorallocatealpha($tile, $red, $green, $blue, max(0, min(127, $alpha)));
        imagettftext($tile, (float) $options['font_size'], 0.0, $padding, $padding + $textHeight, $color, $fontPath, (string) $options['text']);

        if ((float) $options['angle'] === 0.0) {
            imagealphablending($tile, true);
            return $tile;
        }

        $rotated = imagerotate($tile, -(float) $options['angle'], $transparent);
        imagedestroy($tile);

        if (! $rotated instanceof GdImage) {
            return new WP_Error('auto_watermark_gd_rotate', __('Unable to rotate watermark tile image.', self::TEXT_DOMAIN));
        }

        imagealphablending($rotated, true);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function drawTiledImageGd(GdImage $image, array $options): true|WP_Error
    {
        $logoPath = $this->getWatermarkImagePath($options);
        if ($logoPath === null) {
            return true;
        }

        $logoMime = (string) (wp_check_filetype($logoPath)['type'] ?? '');
        $logo = $this->openGdImage($logoPath, $logoMime);

        if (! $logo instanceof GdImage) {
            return new WP_Error('auto_watermark_gd_logo', __('Unable to load the selected watermark image.', self::TEXT_DOMAIN));
        }

        $targetWidth = max(24, (int) round(imagesx($image) * ((int) $options['image_scale'] / 100)));
        $targetHeight = max(24, (int) round(imagesy($logo) * ($targetWidth / max(1, imagesx($logo)))));
        $scaledLogo = $this->scaleGdImage($logo, $targetWidth, $targetHeight);
        imagedestroy($logo);

        if (! $scaledLogo instanceof GdImage) {
            return new WP_Error('auto_watermark_gd_logo_scale', __('Unable to scale the selected watermark image.', self::TEXT_DOMAIN));
        }

        $this->applyGdOpacity($scaledLogo, (float) $options['opacity']);
        [$spacingX, $spacingY] = $this->computeSpacing($options, imagesx($scaledLogo), imagesy($scaledLogo));
        [$width, $height] = [imagesx($image), imagesy($image)];

        for ($y = -$height; $y < $height * 2; $y += $spacingY) {
            for ($x = -$width; $x < $width * 2; $x += $spacingX) {
                imagecopy($image, $scaledLogo, $x, $y, 0, 0, imagesx($scaledLogo), imagesy($scaledLogo));
            }
        }

        imagedestroy($scaledLogo);

        return true;
    }

    private function openGdImage(string $path, string $mime): GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function scaleGdImage(GdImage $source, int $width, int $height): GdImage|false
    {
        $scaled = imagecreatetruecolor($width, $height);
        if (! $scaled instanceof GdImage) {
            return false;
        }

        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefill($scaled, 0, 0, $transparent);
        imagecopyresampled($scaled, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $scaled;
    }

    private function applyGdOpacity(GdImage $image, float $opacity): void
    {
        $opacity = max(0.05, min(1.0, $opacity));
        $width = imagesx($image);
        $height = imagesy($image);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $newAlpha = (int) round(127 - ((127 - $alpha) * $opacity));
                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;
                $color = imagecolorallocatealpha($image, $red, $green, $blue, max(0, min(127, $newAlpha)));
                imagesetpixel($image, $x, $y, $color);
            }
        }
    }

    private function shouldRenderText(array $options): bool
    {
        return in_array((string) $options['type'], ['text', 'both'], true) && trim((string) $options['text']) !== '';
    }

    private function shouldRenderImage(array $options): bool
    {
        return in_array((string) $options['type'], ['image', 'both'], true) && absint($options['image_id'] ?? 0) > 0;
    }

    private function computeSpacing(array $options, int $contentWidth, int $contentHeight): array
    {
        return [
            max($contentWidth + 40, (int) $options['spacing_x']),
            max($contentHeight + 40, (int) $options['spacing_y']),
        ];
    }

    private function findFontPath(string $text = ''): ?string
    {
        $candidates = $this->containsArabic($text)
            ? [
                '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            ]
            : [
                '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
            ];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function hexToRgba(string $hex, float $opacity): string
    {
        [$red, $green, $blue] = $this->hexToRgb($hex);

        return sprintf('rgba(%d, %d, %d, %.3f)', $red, $green, $blue, max(0.05, min(1.0, $opacity)));
    }

    private function hexToRgb(string $hex): array
    {
        $normalized = ltrim($hex, '#');

        return [
            hexdec(substr($normalized, 0, 2)),
            hexdec(substr($normalized, 2, 2)),
            hexdec(substr($normalized, 4, 2)),
        ];
    }

    private function logError(WP_Error $error): void
    {
        error_log(sprintf('[Auto Watermark] %s: %s', $error->get_error_code(), $error->get_error_message()));
    }

    private function containsArabic(string $text): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text) === 1;
    }

    private function debug(string $event, array $context = []): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }

        $payload = $context === [] ? '' : ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log(sprintf('[Auto Watermark][debug] %s%s', $event, $payload));
    }

    private function isDebugEnabled(): bool
    {
        $flag = getenv('AUTO_WATERMARK_DEBUG');

        if ($flag !== false) {
            return in_array(strtolower((string) $flag), ['1', 'true', 'yes', 'on'], true);
        }

        return defined('WP_DEBUG') && WP_DEBUG;
    }

    private function summarizeUpload(array $upload): array
    {
        return [
            'file' => isset($upload['file']) && is_string($upload['file']) ? $upload['file'] : null,
            'type' => isset($upload['type']) && is_string($upload['type']) ? $upload['type'] : null,
            'error' => isset($upload['error']) && $upload['error'] !== '' ? $upload['error'] : null,
        ];
    }
}
