#!/usr/bin/env ruby
# init/01_create_test_project.rb
# Working Redmine 5.0 initialization script based on official documentation

Rails.application.eager_load!

puts "=== Redmine 5.0 Initialization for MediaWiki Support System ==="

begin
  # Create API user for MediaWiki integration
  api_user = User.find_or_create_by(login: 'asuadmin') do |user|
    user.firstname = 'admin'
    user.lastname = 'ASU'
    user.mail = 'asuadmin@noreply.com'
    user.password = 'asupassword'
    user.password_confirmation = 'asupassword'
    user.admin = true
    user.status = User::STATUS_ACTIVE
  end

  if api_user.persisted?
    # Generate API key using the correct method for Redmine 5.0
    api_key = Token.find_or_create_by(user: api_user, action: 'api') do |token|
      token.value = Redmine::Utils.random_hex(40)
    end
    
    puts "✓ API User created: #{api_user.login}"
    puts "✓ API Key: #{api_key.value}"
    puts "  Update MediaWiki LocalSettings.php with: \$wgSupportSystemRedmineAPIKey = '#{api_key.value}';"
  else
    puts "✗ Failed to create API user: #{api_user.errors.full_messages.join(', ')}"
    exit 1
  end

  # Use existing statuses and priorities (don't create new ones)
  existing_statuses = IssueStatus.all
  existing_priorities = IssuePriority.all
  existing_trackers = Tracker.all

  puts "✓ Found #{existing_statuses.count} issue statuses: #{existing_statuses.map(&:name).join(', ')}"
  puts "✓ Found #{existing_priorities.count} priorities: #{existing_priorities.map(&:name).join(', ')}"
  puts "✓ Found #{existing_trackers.count} trackers: #{existing_trackers.map(&:name).join(', ')}"

  # Get references to existing objects
  new_status = existing_statuses.find { |s| s.name == 'New' } || existing_statuses.first
  normal_priority = existing_priorities.find { |p| p.name == 'Normal' } || existing_priorities.first
  
  # Create Support Project using documented approach
  support_project = Project.find_or_create_by(identifier: 'support-system') do |project|
    project.name = 'Support System'
    project.description = 'MediaWiki Support System integration project for issue tracking and knowledge management'
    project.homepage = ''
    project.is_public = true
    project.status = Project::STATUS_ACTIVE
  end

  if support_project.persisted?
    puts "✓ Support project created: #{support_project.name} (ID: #{support_project.id})"

    # Enable standard modules that exist in Redmine 5.0
    available_modules = %w[
      issue_tracking
      time_tracking
      news
      documents
      files
      wiki
      repository
      boards
      calendar
      gantt
    ]

    # Check if helpdesk module is available
    if Redmine::Plugin.installed?(:redmine_helpdesk)
      available_modules << 'helpdesk'
      puts "✓ HelpDesk plugin detected"
    end

    # Enable modules for the project
    support_project.enabled_module_names = available_modules
    support_project.save!

    puts "✓ Enabled modules: #{support_project.enabled_modules.map(&:name).join(', ')}"

    # Assign all existing trackers to project (standard approach)
    support_project.trackers = existing_trackers
    support_project.save!

    puts "✓ Assigned #{existing_trackers.count} trackers to project"

    # Add API user to project with Manager role
    manager_role = Role.find_by(name: 'Manager') || Role.givable.first
    
    if manager_role
      member = Member.find_or_create_by(project: support_project, user: api_user) do |m|
        m.roles = [manager_role]
      end
      puts "✓ API user added to project with #{manager_role.name} role"
    end

    # Create sample issues using existing objects
    sample_issues = [
      {
        subject: 'MediaWiki Support System Integration Test',
        description: 'This is a test issue created during system initialization to verify the integration between MediaWiki and Redmine is working correctly.',
        tracker: existing_trackers.first,
        priority: normal_priority,
        status: new_status
      },
      {
        subject: 'Configure HelpDesk Email Integration',
        description: 'Set up email-to-ticket conversion using the HelpDesk plugin to allow users to create tickets by sending emails to asuadmin@noreplu.com.',
        tracker: existing_trackers.find { |t| t.name == 'Task' } || existing_trackers.first,
        priority: existing_priorities.find { |p| p.name == 'High' } || normal_priority,
        status: new_status
      },
      {
        subject: 'Knowledge Base Documentation',
        description: 'Create initial documentation in the project wiki explaining how to use the Support System and its integration with MediaWiki.',
        tracker: existing_trackers.find { |t| t.name == 'Feature' } || existing_trackers.first,
        priority: normal_priority,
        status: new_status
      }
    ]

    created_issues = []
    sample_issues.each_with_index do |issue_data, index|
      issue = Issue.find_or_create_by(
        project: support_project,
        subject: issue_data[:subject]
      ) do |i|
        i.description = issue_data[:description]
        i.tracker = issue_data[:tracker]
        i.author = api_user
        i.priority = issue_data[:priority]
        i.status = issue_data[:status]
        i.start_date = Date.current
        i.estimated_hours = [2, 4, 8].sample
      end
      
      if issue.persisted?
        created_issues << issue
      end
    end

    puts "✓ Created #{created_issues.count} sample issues"

    # Initialize project wiki if available
    if support_project.respond_to?(:wiki) && support_project.wiki.nil?
      wiki = support_project.create_wiki(start_page: 'Wiki')
      if wiki.save
        puts "✓ Project wiki initialized"
      end
    end

    # Create a version for project management
    version = Version.find_or_create_by(project: support_project, name: 'v1.0') do |v|
      v.description = 'Initial release of MediaWiki-Redmine integration'
      v.status = 'open'
      v.sharing = 'none'
      v.due_date = 3.months.from_now
    end

    puts "✓ Project version created: #{version.name}"
  else
    puts "✗ Failed to create project: #{support_project.errors.full_messages.join(', ')}"
    exit 1
  end

  # Configure essential system settings
  Setting.rest_api_enabled = 1
  Setting.jsonp_enabled = 1
  Setting.app_title = 'Support System - Redmine'
  
  unless Setting.mail_from.present?
    Setting.mail_from = 'support@localhost'
  end

  puts "✓ System settings configured"

  # Display summary
  puts "\n=== Initialization Complete ==="
  puts "Project URL: http://localhost:3000/projects/support-system"
  puts "API Key: #{Token.find_by(user: api_user, action: 'api')&.value}"
  puts ""
  puts "Project Statistics:"
  puts "- Project ID: #{support_project.id}"
  puts "- Enabled Modules: #{support_project.enabled_modules.count}"
  puts "- Available Trackers: #{support_project.trackers.count}"
  puts "- Sample Issues: #{support_project.issues.count}"
  puts "- Available Statuses: #{existing_statuses.count}"
  puts "- Available Priorities: #{existing_priorities.count}"
  puts ""
  puts "MediaWiki Configuration:"
  puts "Add this line to your LocalSettings.php:"
  puts "\$wgSupportSystemRedmineAPIKey = '#{Token.find_by(user: api_user, action: 'api')&.value}';"
  puts ""
  puts "Next Steps:"
  puts "1. Update MediaWiki configuration with the API key above"
  puts "2. Test ticket creation from MediaWiki Support System"
  puts "3. Configure email settings in Redmine Administration if needed"
  puts "4. Set up HelpDesk plugin for email-to-ticket conversion"

rescue => e
  puts "✗ Error during initialization: #{e.message}"
  puts "Error details: #{e.class.name}"
  puts "Stack trace:"
  puts e.backtrace.first(5).join("\n")
  exit 1
end