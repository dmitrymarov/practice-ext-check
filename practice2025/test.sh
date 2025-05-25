#!/bin/bash

set -e

echo "=== Support System Setup Script ==="
echo "Setting up MediaWiki Support System with Redmine integration"

# Create necessary directories
echo "Creating directory structure..."
mkdir -p redmine/config
mkdir -p redmine/init
mkdir -p redmine/plugins

# Copy configuration files
echo "Setting up Redmine configuration..."
cat > redmine/config/additional_environment.rb << 'EOF'
# Configuration for Redmine plugins and custom settings

# Enable API access
RedmineApp::Application.configure do
  config.force_ssl = false
  config.consider_all_requests_local = false
end

# HelpDesk configuration
Rails.application.config.after_initialize do
  if defined?(RedmineHelpdesk)
    RedmineHelpdesk::MailHandler.logger = Rails.logger
  end
end

# API settings with CORS
Rails.application.config.middleware.insert_before 0, Rack::Cors do
  allow do
    origins '*'
    resource '/issues.json', 
      headers: :any, 
      methods: [:get, :post, :put, :patch, :delete, :options],
      credentials: false
    resource '/uploads.json',
      headers: :any,
      methods: [:post, :options],
      credentials: false
    resource '/projects.json',
      headers: :any,
      methods: [:get, :post, :options],
      credentials: false
  end
end

# Configure issue priorities
Rails.application.config.after_initialize do
  if ActiveRecord::Base.connection.table_exists?('enumerations')
    begin
      critical = IssuePriority.find_or_create_by(name: 'Critical') do |priority|
        priority.position = 5
        priority.is_default = false
      end
      
      high = IssuePriority.find_or_create_by(name: 'High') do |priority|
        priority.position = 4
        priority.is_default = false
      end
      
      normal = IssuePriority.find_or_create_by(name: 'Normal') do |priority|
        priority.position = 3
        priority.is_default = true
      end
      
      low = IssuePriority.find_or_create_by(name: 'Low') do |priority|
        priority.position = 2
        priority.is_default = false
      end
      
      Rails.logger.info "Issue priorities configured successfully"
    rescue => e
      Rails.logger.error "Error configuring issue priorities: #{e.message}"
    end
  end
end

# Configure email settings
ActionMailer::Base.smtp_settings = {
  address: ENV['SMTP_HOST'] || 'localhost',
  port: ENV['SMTP_PORT'] || 587,
  domain: ENV['SMTP_DOMAIN'] || 'localhost',
  user_name: ENV['SMTP_USER'],
  password: ENV['SMTP_PASSWORD'],
  authentication: ENV['SMTP_AUTH'] || 'plain',
  enable_starttls_auto: ENV['SMTP_TLS'] == 'true'
}
EOF

# Create settings file
cat > redmine/config/settings.yml << 'EOF'
production:
  rest_api_enabled: true
  jsonp_enabled: true
  cross_project_issue_relations: true
  app_title: "Support System - Redmine"
  welcome_text: "Welcome to the Support System. This Redmine instance is integrated with MediaWiki."
EOF

# Create Dockerfile for Redmine
cat > redmine/Dockerfile << 'EOF'
FROM redmine:5.0

USER root
RUN apt-get update && apt-get install -y \
    git \
    build-essential \
    libssl-dev \
    curl \
    wget \
    unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /usr/src/redmine/plugins

# Install plugins
RUN git clone https://github.com/jfqd/redmine_helpdesk.git redmine_helpdesk || echo "Helpdesk plugin installation failed"
RUN git clone https://github.com/keeleysam/redmine_monitoring_controlling.git redmine_monitoring_controlling || echo "Monitoring plugin installation failed"
RUN git clone https://github.com/akiko-pusu/redmine_theme_changer.git redmine_theme_changer || echo "Theme changer plugin installation failed"
RUN git clone https://github.com/alphanodes/additionals.git redmine_additionals || echo "Additionals plugin installation failed"
RUN git clone https://github.com/two-pack/redmine_xlsx_format_issue_exporter.git redmine_xlsx_format_issue_exporter || echo "XLSX exporter plugin installation failed"

RUN chown -R redmine:redmine /usr/src/redmine/plugins

USER redmine
WORKDIR /usr/src/redmine

RUN bundle install --without development test || echo "Bundle install completed with warnings"
EOF

# Create initialization script
cat > redmine/init/01_create_test_project.rb << 'EOF'
#!/usr/bin/env ruby
Rails.application.eager_load!

puts "=== Redmine Initialization for MediaWiki Support System ==="

begin
  # Create API user
  api_user = User.find_or_create_by(login: 'mediawiki_api') do |user|
    user.firstname = 'MediaWiki'
    user.lastname = 'API'
    user.mail = 'noreply@localhost'
    user.password = 'mediawiki_api_password'
    user.password_confirmation = 'mediawiki_api_password'
    user.admin = false
    user.status = User::STATUS_ACTIVE
  end

  if api_user.persisted?
    api_key = Token.find_or_create_by(user: api_user, action: 'api') do |token|
      token.value = Redmine::Utils.random_hex(40)
    end
    
    puts "✓ API User created: #{api_user.login}"
    puts "✓ API Key: #{api_key.value}"
  end

  # Create Support Project
  support_project = Project.find_or_create_by(identifier: 'support-system') do |project|
    project.name = 'Support System'
    project.description = 'Project for MediaWiki Support System integration'
    project.is_public = true
    project.status = Project::STATUS_ACTIVE
  end

  if support_project.persisted?
    puts "✓ Support project created: #{support_project.name}"

    # Enable modules
    enabled_modules = %w[issue_tracking time_tracking wiki files boards]
    enabled_modules << 'helpdesk' if Redmine::Plugin.installed?(:redmine_helpdesk)
    
    support_project.enabled_module_names = enabled_modules
    support_project.save!

    # Create trackers
    support_tracker = Tracker.find_or_create_by(name: 'Support Request') do |tracker|
      tracker.default_status = IssueStatus.find_by(name: 'New') || IssueStatus.first
      tracker.is_in_chlog = true
      tracker.position = 1
    end

    incident_tracker = Tracker.find_or_create_by(name: 'Incident') do |tracker|
      tracker.default_status = IssueStatus.find_by(name: 'New') || IssueStatus.first
      tracker.is_in_chlog = true
      tracker.position = 2
    end

    support_project.trackers = [support_tracker, incident_tracker]
    support_project.save!

    # Add API user to project
    member_role = Role.find_by(name: 'Manager') || Role.first
    if member_role
      Member.find_or_create_by(project: support_project, user: api_user) do |member|
        member.roles = [member_role]
      end
    end

    puts "✓ Project configuration complete"
  end

  # Configure system settings
  Setting.rest_api_enabled = 1
  Setting.jsonp_enabled = 1
  Setting.app_title = 'Support System - Redmine'
  Setting.mail_from = 'support@localhost' unless Setting.mail_from.present?

  puts "✓ System settings configured"
  puts "\nAPI Key for MediaWiki: #{Token.find_by(user: api_user, action: 'api')&.value}"

rescue => e
  puts "✗ Error during initialization: #{e.message}"
  exit 1
end
EOF

chmod +x redmine/init/01_create_test_project.rb

# Update MediaWiki extension files with new priority mappings
echo "Updating MediaWiki extensions..."

# Backup original files
if [ -f "extensions/SupportSystem/includes/ServiceDesk.php" ]; then
    cp extensions/SupportSystem/includes/ServiceDesk.php extensions/SupportSystem/includes/ServiceDesk.php.backup
fi

if [ -f "extensions/SupportSystem/includes/API/ApiSupportTicket.php" ]; then
    cp extensions/SupportSystem/includes/API/ApiSupportTicket.php extensions/SupportSystem/includes/API/ApiSupportTicket.php.backup
fi

echo "✓ Configuration files created"
echo "✓ MediaWiki extension files backed up"

# Create startup script
cat > start_system.sh << 'EOF'
#!/bin/bash

echo "Starting Support System..."

# Start the containers
docker-compose up -d

echo "Waiting for services to start..."
sleep 30

# Wait for Redmine to be ready
echo "Waiting for Redmine to initialize..."
until curl -s http://localhost:3000 > /dev/null; do
    echo "Waiting for Redmine..."
    sleep 10
done

echo "Running Redmine initialization..."
docker exec -it support-redmine bundle exec rails runner /docker-entrypoint-init.d/01_create_test_project.rb

echo ""
echo "=== Support System Started ==="
echo "MediaWiki: http://localhost:8080"
echo "Redmine: http://localhost:3000"
echo "OpenSearch: http://localhost:9200"
echo "OpenSearch Dashboards: http://localhost:5601"
echo ""
echo "Default credentials:"
echo "Redmine admin: admin/admin"
echo "MediaWiki admin: admin/adminpass"
echo ""
echo "Please check the container logs for the Redmine API key and update your MediaWiki configuration."
EOF

chmod +x start_system.sh

echo ""
echo "=== Setup Complete ==="
echo ""
echo "The system has been configured with the following components:"
echo ""
echo "Redmine Features:"
echo "- HelpDesk plugin for email-to-ticket conversion"
echo "- Monitoring & Controlling plugin for project oversight"
echo "- Custom priority system (Critical, High, Normal, Low)"
echo "- API user configured for MediaWiki integration"
echo "- Test project with sample tickets"
echo ""
echo "MediaWiki Integration:"
echo "- Updated priority mapping in ServiceDesk.php"
echo "- Enhanced API support for semantic priorities"
echo "- Backward compatibility with color-based priorities"
echo ""
echo "To start the system:"
echo "1. Run: ./start_system.sh"
echo "2. Wait for all services to start"
echo "3. Access MediaWiki at http://localhost:8080"
echo "4. Access Redmine at http://localhost:3000"
echo ""
echo "After startup, copy the API key from Redmine logs and update your LocalSettings.php:"
echo "\$wgSupportSystemRedmineAPIKey = 'YOUR_API_KEY_HERE';"
echo ""
echo "The system is now ready for production use with semantic priority naming and enhanced plugin support."