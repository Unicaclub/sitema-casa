<?php

namespace ERP\Core\Performance;

/**
 * Otimizador de Assets (CSS, JS, Imagens)
 */
class AssetOptimizer
{
    private string $publicPath;
    private string $cachePath;
    private array $config;
    
    public function __construct(string $publicPath, array $config = [])
    {
        $this->publicPath = rtrim($publicPath, '/');
        $this->cachePath = $this->publicPath . '/cache';
        $this->config = array_merge([
            'minify_css' => true,
            'minify_js' => true,
            'compress_images' => true,
            'gzip_assets' => true,
            'cache_busting' => true,
            'cdn_url' => null
        ], $config);
        
        $this->ensureCacheDirectory();
    }
    
    /**
     * Otimiza e combina arquivos CSS
     */
    public function optimizeCSS(array $files): string
    {
        $cacheKey = 'css_' . md5(implode('|', $files));
        $cacheFile = $this->cachePath . '/' . $cacheKey . '.css';
        
        if (file_exists($cacheFile) && !$this->needsRebuild($files, $cacheFile)) {
            return $this->getAssetUrl($cacheFile);
        }
        
        $combined = '';
        foreach ($files as $file) {
            $fullPath = $this->publicPath . '/' . ltrim($file, '/');
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                
                // Processar imports CSS
                $content = $this->processCSSimports($content, dirname($fullPath));
                
                // Otimizar URLs relativos
                $content = $this->optimizeCSSUrls($content, dirname($file));
                
                $combined .= $content . "\n";
            }
        }
        
        if ($this->config['minify_css']) {
            $combined = $this->minifyCSS($combined);
        }
        
        file_put_contents($cacheFile, $combined);
        
        if ($this->config['gzip_assets']) {
            file_put_contents($cacheFile . '.gz', gzencode($combined, 9));
        }
        
        return $this->getAssetUrl($cacheFile);
    }
    
    /**
     * Otimiza e combina arquivos JavaScript
     */
    public function optimizeJS(array $files): string
    {
        $cacheKey = 'js_' . md5(implode('|', $files));
        $cacheFile = $this->cachePath . '/' . $cacheKey . '.js';
        
        if (file_exists($cacheFile) && !$this->needsRebuild($files, $cacheFile)) {
            return $this->getAssetUrl($cacheFile);
        }
        
        $combined = '';
        foreach ($files as $file) {
            $fullPath = $this->publicPath . '/' . ltrim($file, '/');
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                $combined .= $content . ";\n";
            }
        }
        
        if ($this->config['minify_js']) {
            $combined = $this->minifyJS($combined);
        }
        
        file_put_contents($cacheFile, $combined);
        
        if ($this->config['gzip_assets']) {
            file_put_contents($cacheFile . '.gz', gzencode($combined, 9));
        }
        
        return $this->getAssetUrl($cacheFile);
    }
    
    /**
     * Otimiza imagem com compressão
     */
    public function optimizeImage(string $imagePath, array $options = []): string
    {
        if (!$this->config['compress_images']) {
            return $imagePath;
        }
        
        $fullPath = $this->publicPath . '/' . ltrim($imagePath, '/');
        if (!file_exists($fullPath)) {
            return $imagePath;
        }
        
        $pathInfo = pathinfo($fullPath);
        $cacheKey = md5($imagePath . filemtime($fullPath) . serialize($options));
        $cacheFile = $this->cachePath . '/' . $cacheKey . '.' . $pathInfo['extension'];
        
        if (file_exists($cacheFile)) {
            return $this->getAssetUrl($cacheFile);
        }
        
        $optimized = $this->compressImage($fullPath, $cacheFile, $options);
        
        return $optimized ? $this->getAssetUrl($cacheFile) : $imagePath;
    }
    
    /**
     * Gera arquivo de manifesto para cache busting
     */
    public function generateManifest(): void
    {
        if (!$this->config['cache_busting']) {
            return;
        }
        
        $manifest = [];
        $cacheFiles = glob($this->cachePath . '/*');
        
        foreach ($cacheFiles as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'gz') {
                continue;
            }
            
            $relativePath = str_replace($this->publicPath, '', $file);
            $hash = substr(md5_file($file), 0, 8);
            $manifest[$relativePath] = $relativePath . '?v=' . $hash;
        }
        
        file_put_contents($this->publicPath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }
    
    /**
     * Limpa cache de assets antigos
     */
    public function clearCache(int $maxAge = 86400): void
    {
        $files = glob($this->cachePath . '/*');
        $now = time();
        
        foreach ($files as $file) {
            if ($now - filemtime($file) > $maxAge) {
                unlink($file);
            }
        }
    }
    
    /**
     * Minifica CSS removendo espaços e comentários
     */
    private function minifyCSS(string $css): string
    {
        // Remove comentários
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // Remove espaços desnecessários
        $css = preg_replace('/\s+/', ' ', $css);
        $css = preg_replace('/;\s*}/', '}', $css);
        $css = preg_replace('/\s*{\s*/', '{', $css);
        $css = preg_replace('/;\s*/', ';', $css);
        $css = preg_replace('/:\s*/', ':', $css);
        
        return trim($css);
    }
    
    /**
     * Minifica JavaScript (básico)
     */
    private function minifyJS(string $js): string
    {
        // Remove comentários simples
        $js = preg_replace('/\/\/.*$/m', '', $js);
        
        // Remove comentários de bloco
        $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js);
        
        // Remove espaços extras
        $js = preg_replace('/\s+/', ' ', $js);
        
        return trim($js);
    }
    
    /**
     * Processa @import no CSS
     */
    private function processCSSimports(string $css, string $basePath): string
    {
        return preg_replace_callback(
            '/@import\s+["\']([^"\']+)["\'];?/',
            function ($matches) use ($basePath) {
                $importPath = $basePath . '/' . $matches[1];
                if (file_exists($importPath)) {
                    return file_get_contents($importPath);
                }
                return $matches[0];
            },
            $css
        );
    }
    
    /**
     * Otimiza URLs no CSS para caminhos absolutos
     */
    private function optimizeCSSUrls(string $css, string $basePath): string
    {
        return preg_replace_callback(
            '/url\s*\(\s*["\']?([^"\')]+)["\']?\s*\)/',
            function ($matches) use ($basePath) {
                $url = $matches[1];
                if (!str_starts_with($url, 'http') && !str_starts_with($url, '//') && !str_starts_with($url, '/')) {
                    $url = $basePath . '/' . $url;
                }
                return 'url("' . $url . '")';
            },
            $css
        );
    }
    
    /**
     * Comprime imagem usando diferentes estratégias
     */
    private function compressImage(string $source, string $destination, array $options): bool
    {
        $quality = $options['quality'] ?? 85;
        $info = getimagesize($source);
        
        if (!$info) {
            return false;
        }
        
        $image = null;
        
        switch ($info['mime']) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = imagecreatefrompng($source);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($source);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = imagecreatefromwebp($source);
                }
                break;
        }
        
        if (!$image) {
            return false;
        }
        
        // Redimensionar se especificado
        if (isset($options['width']) || isset($options['height'])) {
            $image = $this->resizeImage($image, $options);
        }
        
        // Salvar com otimização
        $success = false;
        switch ($info['mime']) {
            case 'image/jpeg':
                $success = imagejpeg($image, $destination, $quality);
                break;
            case 'image/png':
                imagesavealpha($image, true);
                $success = imagepng($image, $destination, 9 - ($quality / 10));
                break;
            case 'image/gif':
                $success = imagegif($image, $destination);
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    $success = imagewebp($image, $destination, $quality);
                }
                break;
        }
        
        imagedestroy($image);
        return $success;
    }
    
    /**
     * Redimensiona imagem mantendo proporção
     */
    private function resizeImage($image, array $options)
    {
        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);
        
        $newWidth = $options['width'] ?? $originalWidth;
        $newHeight = $options['height'] ?? $originalHeight;
        
        // Manter proporção
        if (isset($options['width']) && !isset($options['height'])) {
            $newHeight = ($originalHeight * $newWidth) / $originalWidth;
        } elseif (!isset($options['width']) && isset($options['height'])) {
            $newWidth = ($originalWidth * $newHeight) / $originalHeight;
        }
        
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preservar transparência PNG
        if (imageistruecolor($image)) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
            imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
        }
        
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        
        return $resized;
    }
    
    /**
     * Verifica se arquivos precisam ser reconstruídos
     */
    private function needsRebuild(array $sourceFiles, string $cacheFile): bool
    {
        if (!file_exists($cacheFile)) {
            return true;
        }
        
        $cacheTime = filemtime($cacheFile);
        
        foreach ($sourceFiles as $file) {
            $fullPath = $this->publicPath . '/' . ltrim($file, '/');
            if (file_exists($fullPath) && filemtime($fullPath) > $cacheTime) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtém URL do asset (com CDN se configurado)
     */
    private function getAssetUrl(string $filePath): string
    {
        $relativePath = str_replace($this->publicPath, '', $filePath);
        
        if ($this->config['cdn_url']) {
            return rtrim($this->config['cdn_url'], '/') . $relativePath;
        }
        
        return $relativePath;
    }
    
    /**
     * Garante que diretório de cache existe
     */
    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }
}