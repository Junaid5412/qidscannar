# Configures the Xcode project that `flutter create` regenerates on every CI run.
# Done with the xcodeproj gem (shipped with CocoaPods on the macOS runners) instead of
# sed, so the edits survive Flutter template changes.
#
#   - adds Runner/PrivacyInfo.xcprivacy to the app bundle (Apple has required an app
#     privacy manifest since May 2024)
#   - iPhone only (TARGETED_DEVICE_FAMILY = 1): an iPad build would need iPad
#     screenshots and an iPad-ready layout before App Review accepts it
#   - bundle id com.qid.scanner, iOS 15.5 minimum (ML Kit)
#   - manual "Apple Distribution" signing when TEAM_ID and PROFILE_NAME are set
#
# Run from mobile_app/:  ruby ci/configure_ios_project.rb
require 'xcodeproj'

BUNDLE_ID = 'com.qid.scanner'
project = Xcodeproj::Project.open('ios/Runner.xcodeproj')
runner = project.targets.find { |t| t.name == 'Runner' } or abort('Runner target not found')

# privacy manifest as a bundle resource
group = project.main_group.find_subpath('Runner', false) or abort('Runner group not found')
manifest = group.files.find { |f| f.path == 'PrivacyInfo.xcprivacy' } || group.new_reference('PrivacyInfo.xcprivacy')
unless runner.resources_build_phase.files_references.include?(manifest)
  runner.resources_build_phase.add_file_reference(manifest, true)
end

team    = ENV['TEAM_ID'].to_s
profile = ENV['PROFILE_NAME'].to_s
signing = !team.empty? && !profile.empty?

runner.build_configurations.each do |config|
  s = config.build_settings
  s['TARGETED_DEVICE_FAMILY']     = '1'
  s['PRODUCT_BUNDLE_IDENTIFIER']  = BUNDLE_ID
  s['IPHONEOS_DEPLOYMENT_TARGET'] = '15.5'
  next unless signing && config.name != 'Debug'

  s['CODE_SIGN_STYLE']                     = 'Manual'
  s['DEVELOPMENT_TEAM']                    = team
  s['PROVISIONING_PROFILE_SPECIFIER']      = profile
  s['CODE_SIGN_IDENTITY']                  = 'Apple Distribution'
  s['CODE_SIGN_IDENTITY[sdk=iphoneos*]']   = 'Apple Distribution'
end

project.save
puts "Runner: privacy manifest added, iPhone only, #{BUNDLE_ID}, " +
     (signing ? "manual signing with profile \"#{profile}\" (team #{team})" : 'no signing (unsigned build)')
