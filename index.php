<?php
/**
 * CRM Showcase Landing Page
 * 
 * This is the entry point when users visit the root URL
 * before they access the public application
 */

// Detect if we should redirect to the app or show showcase
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUrl = parse_url($requestUri);
$path = trim($parsedUrl['path'] ?? '/', '/');
$queryString = !empty($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';

// Detect base path dynamically
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$isLocalhost = strpos($httpHost, 'localhost') !== false 
            || strpos($httpHost, '127.0.0.1') !== false
            || strpos($httpHost, '::1') !== false;

// Determine base path
if ($isLocalhost) {
    $basePath = '/crm';
} else {
    // Production - detect from script name or use empty
    $basePath = strpos($scriptName, '/crm/') !== false ? '/crm' : '';
}

// Root path: serve the entrance page at the canonical homepage URL.
// Direct /entrance.php visits declare the same canonical URL, but the public
// homepage itself remains a crawlable 200 response instead of a temporary 302.
$rootPaths = ['', 'crm', 'index.php', 'index.html', 'crm/index.php', 'crm/index.html'];
if (in_array($path, $rootPaths)) {
    require __DIR__ . '/entrance.php';
    exit;
}

// If user is trying to access a specific path, redirect to public
if (!empty($path)) {
    // Remove 'crm' from path if present
    $cleanPath = str_replace('crm/', '', $path);
    $cleanPath = str_replace('crm', '', $cleanPath);
    $cleanPath = trim($cleanPath, '/');
    
    if (!empty($cleanPath)) {
        // Preserve query string in redirect
        header('Location: ' . $basePath . '/public/' . $cleanPath . $queryString);
        exit;
    }
}

// Show showcase page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM System - Customer Relationship Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #e0e5ec;
            --input: #ecf0f3;
            --shadow-dark: #a3b1c6;
            --shadow-light: #ffffff;
            --accent: #9db2bf;
            --text: #555;
        }

        body.pink {
            --bg: #f5e9ec;
            --input: #fdf6f9;
            --shadow-dark: #d4b9c3;
            --shadow-light: #fff;
            --accent: #e38cad;
            --text: #444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: background 0.4s ease;
            position: relative;
            overflow-x: hidden;
        }

        .video-background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
        }

        .video-background video {
            min-width: 100%;
            min-height: 100%;
            width: auto;
            height: auto;
            object-fit: cover;
            opacity: 0.25;
        }

        
        .showcase-container {
            position: relative;
            z-index: 1;
            background: var(--bg);
            border-radius: 25px;
            box-shadow: 12px 12px 30px var(--shadow-dark), -12px -12px 30px var(--shadow-light);
            max-width: 1200px;
            width: 100%;
            padding: 70px 60px;
            text-align: center;
            position: relative;
            animation: fadeSlideIn 0.6s ease;
        }

        @keyframes fadeSlideIn {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .showcase-container::before {
            content: '';
            position: absolute;
            top: -10px;
            left: -10px;
            right: -10px;
            bottom: -10px;
            border-radius: 35px;
            background: linear-gradient(145deg, var(--shadow-light), var(--shadow-dark));
            z-index: -1;
            opacity: 0.5;
        }
        
        .logo {
            font-size: 48px;
            font-weight: bold;
            color: var(--accent);
            margin-bottom: 25px;
            text-shadow: 2px 2px 4px var(--shadow-dark), -2px -2px 4px var(--shadow-light);
        }
        
        h1 {
            color: var(--text);
            font-size: 36px;
            margin-bottom: 20px;
            font-weight: 600;
            padding: 0 20px;
        }
        
        .subtitle {
            color: var(--text);
            opacity: 0.8;
            font-size: 20px;
            margin-bottom: 50px;
            line-height: 1.6;
            padding: 0 40px;
        }
        
        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 35px;
            margin: 50px 0;
            text-align: left;
            padding: 0 20px;
        }
        
        .feature {
            padding: 35px 30px;
            background: var(--input);
            border-radius: 15px;
            box-shadow: inset 5px 5px 10px var(--shadow-dark),
                        inset -5px -5px 10px var(--shadow-light),
                        5px 5px 10px var(--shadow-dark),
                        -5px -5px 10px var(--shadow-light);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .feature:hover {
            box-shadow: inset 3px 3px 6px var(--shadow-dark),
                        inset -3px -3px 6px var(--shadow-light),
                        8px 8px 15px var(--shadow-dark),
                        -8px -8px 15px var(--shadow-light);
            transform: translateY(-2px);
        }
        
        .feature-icon {
            font-size: 64px;
            margin-bottom: 20px;
            line-height: 1;
            display: block;
            transition: all 0.3s ease;
        }

        .feature-icon i {
            display: inline-block;
        }

        /* Vivid colors for each icon */
        .feature-icon .fa-users {
            color: #667eea;
            text-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        .feature-icon .fa-briefcase {
            color: #f59e0b;
            text-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }

        .feature-icon .fa-comments {
            color: #10b981;
            text-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .feature-icon .fa-brain {
            color: #ec4899;
            text-shadow: 0 2px 8px rgba(236, 72, 153, 0.3);
        }

        .feature-icon .fa-chart-line {
            color: #3b82f6;
            text-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .feature-icon .fa-cogs {
            color: #8b5cf6;
            text-shadow: 0 2px 8px rgba(139, 92, 246, 0.3);
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .feature-icon.automation-icon i {
            animation: rotate 3s linear infinite;
        }

        .feature-icon.ai-icon i {
            animation: pulse 2s ease-in-out infinite;
        }

        .feature:hover .feature-icon {
            transform: scale(1.15);
        }

        .feature:hover .feature-icon.automation-icon i {
            animation-duration: 2s;
        }
        
        .feature h3 {
            color: var(--text);
            font-size: 19px;
            margin-bottom: 15px;
            font-weight: 600;
            line-height: 1.3;
        }
        
        .feature p {
            color: var(--text);
            opacity: 0.75;
            font-size: 14px;
            line-height: 1.7;
            flex-grow: 1;
            margin: 0;
        }
        
        .ai-highlights {
            margin-top: 45px;
            padding: 30px 20px;
            text-align: center;
        }

        .ai-highlights-title {
            color: var(--text);
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 20px;
            opacity: 0.9;
        }

        .ai-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 20px;
            justify-content: center;
            align-items: center;
        }

        .ai-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: var(--input);
            border-radius: 10px;
            font-size: 13px;
            color: var(--text);
            opacity: 0.9;
            box-shadow: inset 3px 3px 6px var(--shadow-dark),
                        inset -3px -3px 6px var(--shadow-light);
            transition: all 0.2s ease;
        }

        .ai-badge:hover {
            box-shadow: 4px 4px 8px var(--shadow-dark),
                        -4px -4px 8px var(--shadow-light);
            transform: translateY(-1px);
        }

        .ai-badge i {
            color: var(--accent);
            font-size: 14px;
        }
        
        .cta-buttons {
            margin-top: 45px;
            display: flex;
            gap: 25px;
            justify-content: center;
            flex-wrap: wrap;
            padding: 0 20px;
        }
        
        .btn {
            padding: 15px 40px;
            font-size: 18px;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            box-shadow: 5px 5px 10px var(--shadow-dark),
                        -5px -5px 10px var(--shadow-light);
        }
        
        .btn-primary {
            background: var(--accent);
            color: white;
        }
        
        .btn-primary:hover {
            transform: scale(1.05);
            box-shadow: 6px 6px 12px var(--shadow-dark),
                        -6px -6px 12px var(--shadow-light);
        }

        .btn-primary:active {
            transform: scale(0.98);
            box-shadow: inset 5px 5px 10px var(--shadow-dark),
                        inset -5px -5px 10px var(--shadow-light);
        }
        
        .btn-secondary {
            background: var(--input);
            color: var(--accent);
            box-shadow: inset 3px 3px 6px var(--shadow-dark),
                        inset -3px -3px 6px var(--shadow-light);
        }
        
        .btn-secondary:hover {
            background: var(--accent);
            color: white;
            box-shadow: 5px 5px 10px var(--shadow-dark),
                        -5px -5px 10px var(--shadow-light);
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 30px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            color: var(--text);
            opacity: 0.6;
            font-size: 14px;
        }
        
        @media (max-width: 1024px) {
            .features {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .showcase-container {
                padding: 40px 20px;
            }
            
            h1 {
                font-size: 28px;
            }
            
            .subtitle {
                font-size: 16px;
            }
            
            .features {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .feature {
                min-height: auto;
            }

            .ai-highlights {
                padding: 25px 15px;
            }

            .ai-badges {
                gap: 10px 14px;
            }

            .ai-badge {
                font-size: 12px;
                padding: 6px 12px;
            }

        }
    </style>
</head>
<body>
    <div class="video-background">
        <video autoplay muted loop playsinline>
            <source src="<?php echo $basePath; ?>/public/assets/videos/lion.mp4" type="video/mp4">
        </video>
    </div>
    <div class="showcase-container">
        <div class="logo">CRM</div>
        <h1>Customer Relationship Management System</h1>
        <p class="subtitle">
            Streamline your sales with AI-powered meeting prep, smart search, natural-language reports, and intelligent deal insights.
        </p>
        
        <div class="features">
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-users"></i></div>
                <h3>Contact Management</h3>
                <p>Organize contacts with semantic search ("high-value leads in tech"), AI meeting prep summaries, and one-click enrichment.</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-briefcase"></i></div>
                <h3>Deal Pipeline</h3>
                <p>Track deals with AI next steps, close-date predictions, deal summaries, and document extraction from proposals.</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-comments"></i></div>
                <h3>Communication Hub</h3>
                <p>Unified inbox with thread summarization, AI-suggested replies, and automated tracking across email and WhatsApp.</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon ai-icon"><i class="fas fa-brain"></i></div>
                <h3>AI-Powered Insights</h3>
                <p>Lead scoring, predictive analytics, form submission routing, and action-item extraction from notes.</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Analytics & Reports</h3>
                <p>Dashboards plus natural-language reports—ask "Top 10 leads this month" or "Deals stuck in negotiation" in plain English.</p>
            </div>
            
            <div class="feature">
                <div class="feature-icon automation-icon"><i class="fas fa-cogs"></i></div>
                <h3>Automation</h3>
                <p>Workflows, email sequences, and AI-driven form routing that suggests tags, stages, and follow-ups.</p>
            </div>
        </div>
        
        <div class="ai-highlights">
            <h3 class="ai-highlights-title">Powered by AI</h3>
            <div class="ai-badges">
                <span class="ai-badge"><i class="fas fa-calendar-check"></i> Meeting Prep</span>
                <span class="ai-badge"><i class="fas fa-search"></i> Semantic Search</span>
                <span class="ai-badge"><i class="fas fa-comment-dots"></i> Natural-Language Reports</span>
                <span class="ai-badge"><i class="fas fa-lightbulb"></i> Deal Intelligence</span>
                <span class="ai-badge"><i class="fas fa-list-ul"></i> Thread Summarization</span>
                <span class="ai-badge"><i class="fas fa-reply"></i> Suggested Replies</span>
                <span class="ai-badge"><i class="fas fa-file-alt"></i> Document Extraction</span>
                <span class="ai-badge"><i class="fas fa-tasks"></i> Form Submission Analysis</span>
            </div>
        </div>
        
        <div class="cta-buttons">
            <a href="<?php echo $basePath; ?>/public/login.php" class="btn btn-primary">Access CRM System</a>
            <a href="<?php echo $basePath; ?>/public/privacy-policy.php" class="btn btn-secondary">Privacy Policy</a>
        </div>
        
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> CRM System. All rights reserved.</p>
        </div>
    </div>

    <script>
    </script>
</body>
</html>
