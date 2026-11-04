// Side-effect CSS imports of components under test (bundled as empty by the test runner).
declare module "*.css";
// STAGE-05D: the real StaffApp imports its logo (bundled as a file by the test runner).
declare module "*.png" {
  const url: string;
  export default url;
}
